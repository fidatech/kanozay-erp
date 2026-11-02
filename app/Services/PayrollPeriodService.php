<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\EmployeeProfile;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPeriodService
{
    public function __construct(private readonly JournalPostingService $posting) {}

    public function create(string $month, int $currencyId, int $actorId): PayrollPeriod
    {
        return DB::transaction(function () use ($month, $currencyId, $actorId) {
            $currency = Currency::whereKey($currencyId)->where('is_active', true)->firstOrFail();
            $periodStart = $month.'-01';
            $existing = PayrollPeriod::where('period_start', $periodStart)->where('currency_id', $currency->id)->first();
            if ($existing) return $existing;

            $employees = EmployeeProfile::where('salary_currency_id', $currency->id)->where('is_active', true)->orderBy('employee_number')->get();
            if ($employees->isEmpty()) throw ValidationException::withMessages(['currency_id' => 'There are no active employees in this currency.']);

            $period = PayrollPeriod::create([
                'period_start' => $periodStart, 'currency_id' => $currency->id,
                'status' => 'draft', 'created_by' => $actorId,
            ]);
            foreach ($employees as $employee) {
                $period->lines()->create([
                    'employee_profile_id' => $employee->id,
                    'salary_snapshot' => (string) $employee->monthly_salary,
                    'payable_amount' => (string) $employee->monthly_salary,
                ]);
            }
            ActivityLog::record('payroll.period.created', 'payroll_period', $period->id, 'Payroll draft created for '.$month.' '.$currency->code);
            return $period;
        });
    }

    public function adjust(PayrollPeriod $period, PayrollLine $line, string $amount, ?string $reason): void
    {
        DB::transaction(function () use ($period, $line, $amount, $reason) {
            $locked = PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $item = PayrollLine::whereKey($line->id)->where('payroll_period_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') $this->reject('Approved payroll amounts cannot be edited.');
            $value = BigDecimal::of($amount);
            if ($value->isLessThan(0) || $value->isGreaterThan(BigDecimal::of('9999999999999999.9999'))) $this->reject('Payable amount is outside the supported range.');
            if ($value->compareTo(BigDecimal::of((string) $item->salary_snapshot)) !== 0 && ! trim((string) $reason)) {
                $this->reject('Explain every change from the salary snapshot.');
            }
            $item->update(['payable_amount' => (string) $value->toScale(4), 'adjustment_reason' => trim((string) $reason) ?: null]);
            ActivityLog::record('payroll.line.adjusted', 'payroll_line', $item->id, 'Draft payroll amount adjusted with reason');
        });
    }

    public function approve(PayrollPeriod $period, int $expenseId, int $payableId, int $actorId): PayrollPeriod
    {
        return DB::transaction(function () use ($period, $expenseId, $payableId, $actorId) {
            $locked = PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') $this->reject('Only a draft payroll period can be approved.');
            $expense = LedgerAccount::whereKey($expenseId)->where('is_active', true)->where('is_group', false)->where('type', 'expense')->first();
            $payable = LedgerAccount::whereKey($payableId)->where('is_active', true)->where('is_group', false)->where('type', 'liability')->first();
            if (! $expense || ! $payable) $this->reject('Select active posting accounts for salary expense and salary payable.');

            $total = BigDecimal::zero();
            $lines = $locked->lines()->lockForUpdate()->get();
            if ($lines->isEmpty()) $this->reject('No employees are included in this payroll period.');
            foreach ($lines as $line) $total = $total->plus((string) $line->payable_amount);
            if (! $total->isGreaterThan(0) || $total->isGreaterThan(BigDecimal::of('9999999999999999.9999'))) $this->reject('Approved payroll total must be positive and within the supported range.');

            DocumentSequence::firstOrCreate(['document_type' => 'payroll'], ['prefix' => 'PAYR-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $number = DocumentSequence::issue('payroll');
            $journalDate = $locked->period_start->copy()->endOfMonth();
            if ($journalDate->isFuture()) $journalDate = now();
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $journalDate->toDateString(),
                'reference' => $number, 'description' => 'Approved payroll '.$locked->period_start->format('Y-m'),
                'currency_id' => $locked->currency_id, 'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->create(['ledger_account_id' => $expense->id, 'debit' => (string) $total->toScale(4), 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $payable->id, 'debit' => '0', 'credit' => (string) $total->toScale(4)]);
            $this->posting->post($journal, $actorId);
            $locked->update([
                'status' => 'approved', 'expense_ledger_id' => $expense->id,
                'payable_ledger_id' => $payable->id, 'approval_journal_id' => $journal->id,
                'approved_by' => $actorId, 'approved_at' => now(),
            ]);
            ActivityLog::record('payroll.period.approved', 'payroll_period', $locked->id, 'Payroll approved: '.$number);
            return $locked;
        });
    }

    public function pay(PayrollPeriod $period, PayrollLine $line, FinancialAccount $source, string $date, string $token, int $actorId): PayrollLine
    {
        return DB::transaction(function () use ($period, $line, $source, $date, $token, $actorId) {
            $locked = PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $item = PayrollLine::whereKey($line->id)->where('payroll_period_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($item->payment_transaction_id) {
                $same = MoneyTransaction::whereKey($item->payment_transaction_id)->where('submission_token', $token)->exists();
                if ($same) return $item;
                $this->reject('This employee line has already been paid.');
            }
            if ($locked->status !== 'approved') $this->reject('Approve the payroll period before paying employees.');
            if (MoneyTransaction::where('submission_token', $token)->exists()) $this->reject('This payment token has already been used.');
            $account = FinancialAccount::whereKey($source->id)->where('is_active', true)->firstOrFail();
            if ((int) $account->currency_id !== (int) $locked->currency_id) $this->reject('Payment account currency must match the payroll currency.');
            $cashLedger = DB::table('entity_ledger_accounts')->where([
                'entity_type' => $account->type, 'entity_id' => $account->id,
                'currency_id' => $locked->currency_id, 'purpose' => 'cash',
            ])->value('ledger_account_id');
            if (! $cashLedger) $this->reject('The payment account needs a linked cash, bank or sarafi ledger.');
            if (BigDecimal::of((string) $item->payable_amount)->compareTo(BigDecimal::zero()) <= 0) $this->reject('A zero-value payroll line cannot be paid.');

            DocumentSequence::firstOrCreate(['document_type' => 'payment'], ['prefix' => 'PAY-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $number = DocumentSequence::issue('payment');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $date, 'reference' => $locked->approvalJournal->number,
                'description' => 'Payroll payment '.$locked->period_start->format('Y-m').' employee '.$item->employee->employee_number,
                'currency_id' => $locked->currency_id, 'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->create(['ledger_account_id' => $locked->payable_ledger_id, 'debit' => $item->payable_amount, 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $cashLedger, 'debit' => '0', 'credit' => $item->payable_amount]);
            $this->posting->post($journal, $actorId);
            $transaction = MoneyTransaction::create([
                'submission_token' => $token, 'type' => 'payment', 'transaction_date' => $date,
                'source_account_id' => $account->id, 'counterpart_ledger_account_id' => $locked->payable_ledger_id,
                'currency_id' => $locked->currency_id, 'amount' => $item->payable_amount,
                'reference' => $locked->approvalJournal->number,
                'description' => 'Payroll '.$locked->period_start->format('Y-m').' employee '.$item->employee->employee_number,
                'journal_entry_id' => $journal->id, 'created_by' => $actorId,
            ]);
            $item->update(['payment_transaction_id' => $transaction->id, 'paid_at' => now()]);
            if (! $locked->lines()->whereNull('payment_transaction_id')->where('payable_amount', '>', 0)->exists()) $locked->update(['status' => 'paid']);
            ActivityLog::record('payroll.line.paid', 'payroll_line', $item->id, 'Payroll payment posted: '.$number);
            return $item;
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['payroll' => $message]);
    }
}
