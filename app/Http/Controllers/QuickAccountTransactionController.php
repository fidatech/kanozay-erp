<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cashbook-first quick posting through a single counterpart selector.
 * Party receipts/payments reuse the established party posting service.
 */
class QuickAccountTransactionController extends Controller
{
    public function store(Request $request, JournalPostingService $posting): RedirectResponse
    {
        abort_unless($request->user()->canAny(['administration.update', 'finance.update']), 403);
        $data = $request->validate([
            'counterpart_key' => ['required', 'string', 'regex:/^(party|financial|ledger):[1-9][0-9]*$/'],
            'cash_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'submission_token' => ['required', 'uuid'],
            'transaction_date' => ['required', 'date'],
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        [$kind, $id] = explode(':', $data['counterpart_key'], 2);
        $id = (int) $id;

        $cash = FinancialAccount::whereKey($data['cash_account_id'])
            ->where('type', 'cashbox')->where('is_active', true)->first();
        if (!$cash || (int) $cash->currency_id !== (int) $data['currency_id']) {
            throw ValidationException::withMessages([
                'cash_account_id' => 'Select an active cashbook in the requested currency.',
            ]);
        }

        if ($kind === 'party') {
            // Existing party journal posting and settlement behavior remains unchanged.
            $request->merge(['trade_party_id' => $id]);
            return app(QuickPartyTransactionController::class)->store($request, $posting);
        }

        $raw = (string) $data['amount'];
        $normalized = strtr($raw, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٫'=>'.','٬'=>',','،'=>','
        ]);
        $amount = str_replace(',', '', $normalized);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D', $amount)
            || \Brick\Math\BigDecimal::of($amount)->isZero()
            || \Brick\Math\BigDecimal::of($amount)->compareTo('999999999999.9999') > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a positive amount with up to four decimal places.',
            ]);
        }

        DB::transaction(function () use ($request, $posting, $data, $kind, $id, $amount) {
            if (MoneyTransaction::where('submission_token', $data['submission_token'])->exists()) {
                return;
            }
            $cash = FinancialAccount::whereKey($data['cash_account_id'])
                ->lockForUpdate()->firstOrFail();
            if (!$cash->is_active || $cash->type !== 'cashbox'
                || (int) $cash->currency_id !== (int) $data['currency_id']) {
                throw ValidationException::withMessages(['cash_account_id' => 'Cashbook currency changed. Please reload.']);
            }
            $cashLedgerId = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'cashbox', 'entity_id' => $cash->id,
                'currency_id' => $cash->currency_id, 'purpose' => 'cash',
            ])->value('ledger_account_id');
            $cashLedger = LedgerAccount::find($cashLedgerId);
            if (!$cashLedger || !$cashLedger->is_active || $cashLedger->is_group) {
                throw ValidationException::withMessages(['cash_account_id' => 'Cashbook ledger mapping is invalid.']);
            }

            $counterpartLedger = null;
            $financial = null;
            if ($kind === 'financial') {
                $financial = FinancialAccount::whereKey($id)->lockForUpdate()->first();
                if (!$financial || !$financial->is_active || $financial->id === $cash->id) {
                    throw ValidationException::withMessages(['counterpart_key' => 'Choose another active financial account.']);
                }
                if ((int) $financial->currency_id !== (int) $cash->currency_id) {
                    throw ValidationException::withMessages(['counterpart_key' => 'Currencies differ. Use Currency Exchange instead.']);
                }
                $ledgerId = DB::table('entity_ledger_accounts')->where([
                    'entity_type' => $financial->type, 'entity_id' => $financial->id,
                    'currency_id' => $cash->currency_id, 'purpose' => 'cash',
                ])->value('ledger_account_id');
                $counterpartLedger = LedgerAccount::find($ledgerId);
            } else {
                // Mapped customer/supplier/financial ledgers must be selected through their
                // entity workflows, never by entering the same ledger as an ordinary GL account.
                $alreadyMapped = DB::table('entity_ledger_accounts')->where('ledger_account_id', $id)->exists();
                if ($alreadyMapped) {
                    throw ValidationException::withMessages(['counterpart_key' => 'Use the mapped customer, supplier or financial account instead.']);
                }
                $counterpartLedger = LedgerAccount::find($id);
            }
            if (!$counterpartLedger || !$counterpartLedger->is_active || $counterpartLedger->is_group
                || (int) $counterpartLedger->id === (int) $cashLedger->id) {
                throw ValidationException::withMessages(['counterpart_key' => 'Choose another active posting account.']);
            }

            $received = $data['type'] === 'credit';
            $transactionType = $financial ? 'transfer' : ($received ? 'receipt' : 'payment');
            $sequenceType = $received ? 'receipt' : 'payment';
            DocumentSequence::firstOrCreate(
                ['document_type' => $sequenceType],
                ['prefix' => $received ? 'REC-' : 'PAY-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]
            );
            $number = DocumentSequence::issue($sequenceType);
            $counterpartName = $financial?->name ?? $counterpartLedger->name;
            $description = trim((string) ($data['description'] ?? ''));
            if ($description === '') {
                $description = ($received ? 'Received from ' : 'Paid to ') . $counterpartName;
            }
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['transaction_date'],
                'description' => $description, 'currency_id' => $cash->currency_id,
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
            $journal->lines()->create([
                'ledger_account_id' => $received ? $cashLedger->id : $counterpartLedger->id,
                'debit' => $amount, 'credit' => '0',
            ]);
            $journal->lines()->create([
                'ledger_account_id' => $received ? $counterpartLedger->id : $cashLedger->id,
                'debit' => '0', 'credit' => $amount,
            ]);
            $posting->post($journal, $request->user()->id);

            $sourceId = $received ? $financial?->id : $cash->id;
            $destinationId = $received ? $cash->id : $financial?->id;
            $transaction = MoneyTransaction::create([
                'submission_token' => $data['submission_token'],
                'type' => $transactionType, 'transaction_date' => $data['transaction_date'],
                'source_account_id' => $sourceId, 'destination_account_id' => $destinationId,
                'counterpart_ledger_account_id' => $financial ? null : $counterpartLedger->id,
                'currency_id' => $cash->currency_id, 'amount' => $amount,
                'description' => $description, 'journal_entry_id' => $journal->id,
                'created_by' => $request->user()->id,
            ]);
            ActivityLog::record(
                'quick_account_transaction.posted', 'money_transaction', $transaction->id,
                'Quick account transaction posted: ' . $number,
                ['counterpart_kind' => $kind, 'counterpart_id' => $id, 'journal_id' => $journal->id]
            );
        });

        return redirect()->route('finance.journals.index')
            ->with('status', 'Transaction posted to the journal and General Ledger.');
    }
}
