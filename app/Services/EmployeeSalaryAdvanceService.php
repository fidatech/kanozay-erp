<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryAdvance;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryAdvanceService
{
    public function __construct(private readonly JournalPostingService $posting) {}

    public function issue(array $data, int $actorId): EmployeeSalaryAdvance
    {
        return DB::transaction(function () use ($data, $actorId) {
            $existing = EmployeeSalaryAdvance::where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                if ((int) $existing->employee_profile_id === (int) $data['employee_profile_id']
                    && BigDecimal::of((string) $existing->amount)->compareTo(BigDecimal::of($data['amount'])) === 0
                    && $existing->issued_on->toDateString() === $data['issued_on']
                    && (int) $existing->transaction->source_account_id === (int) $data['source_account_id']
                    && (int) $existing->receivable_ledger_id === (int) $data['receivable_ledger_id']
                    && (int) $existing->created_by === $actorId
                    && $existing->reason === $data['reason']) return $existing;
                throw ValidationException::withMessages(['submission_token' => 'This token was already used for a different advance.']);
            }
            $employee = EmployeeProfile::whereKey($data['employee_profile_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $account = FinancialAccount::whereKey($data['source_account_id'])->where('is_active', true)->firstOrFail();
            if ((int) $employee->salary_currency_id !== (int) $account->currency_id) throw ValidationException::withMessages(['source_account_id' => 'The payment account must use the employee salary currency.']);
            $receivable = LedgerAccount::whereKey($data['receivable_ledger_id'])->where('type', 'asset')->where('is_group', false)->where('is_active', true)->first();
            if (! $receivable) throw ValidationException::withMessages(['receivable_ledger_id' => 'Select an active employee advance receivable account.']);
            $cashLedgerId = DB::table('entity_ledger_accounts')->where([
                'entity_type' => $account->type, 'entity_id' => $account->id,
                'currency_id' => $account->currency_id, 'purpose' => 'cash',
            ])->value('ledger_account_id');
            if (! $cashLedgerId || (int) $cashLedgerId === $receivable->id) throw ValidationException::withMessages(['source_account_id' => 'Choose a mapped cash, bank or sarafi account separate from the advance receivable.']);
            $amount = BigDecimal::of($data['amount']);
            if (! $amount->isGreaterThan(0) || $amount->isGreaterThan(BigDecimal::of('9999999999999999.9999'))) throw ValidationException::withMessages(['amount' => 'Enter a positive amount within the supported range.']);

            DocumentSequence::firstOrCreate(['document_type' => 'employee_advance'], ['prefix' => 'EADV-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $number = DocumentSequence::issue('employee_advance');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['issued_on'], 'reference' => $number,
                'description' => 'Salary advance to employee '.$employee->employee_number,
                'currency_id' => $account->currency_id, 'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->create(['ledger_account_id' => $receivable->id, 'debit' => (string) $amount->toScale(4), 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $cashLedgerId, 'debit' => '0', 'credit' => (string) $amount->toScale(4)]);
            $this->posting->post($journal, $actorId);
            $transaction = MoneyTransaction::create([
                'submission_token' => $data['submission_token'], 'type' => 'payment', 'transaction_date' => $data['issued_on'],
                'source_account_id' => $account->id, 'counterpart_ledger_account_id' => $receivable->id,
                'currency_id' => $account->currency_id, 'amount' => (string) $amount->toScale(4),
                'reference' => $number, 'description' => 'Salary advance to employee '.$employee->employee_number,
                'journal_entry_id' => $journal->id, 'created_by' => $actorId,
            ]);
            $advance = EmployeeSalaryAdvance::create([
                'employee_profile_id' => $employee->id, 'currency_id' => $account->currency_id,
                'amount' => (string) $amount->toScale(4), 'issued_on' => $data['issued_on'],
                'receivable_ledger_id' => $receivable->id, 'money_transaction_id' => $transaction->id,
                'submission_token' => $data['submission_token'], 'reason' => $data['reason'], 'created_by' => $actorId,
            ]);
            ActivityLog::record('hr.advance.issued', 'employee_salary_advance', $advance->id, 'Salary advance issued: '.$number);
            return $advance;
        });
    }
}
