<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAdvanceService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    public function remaining(int $receiptId): BigDecimal
    {
        $receipt = DB::table('customer_advance_receipts')->where('id', $receiptId)->first();
        if (! $receipt) return BigDecimal::zero();

        $left = BigDecimal::of((string) $receipt->amount);
        foreach (DB::table('customer_advance_applications')
            ->where('customer_advance_receipt_id', $receiptId)->pluck('amount') as $amount) {
            $left = $left->minus((string) $amount);
        }
        foreach (DB::table('customer_advance_refunds')
            ->where('customer_advance_receipt_id', $receiptId)->pluck('amount') as $amount) {
            $left = $left->minus((string) $amount);
        }

        return $left;
    }

    public function receive(TradeParty $customer, array $data, int $actorId): object
    {
        return DB::transaction(function () use ($customer, $data, $actorId) {
            $locked = TradeParty::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $amount = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::Unnecessary);
            if (! $amount->isPositive() || $amount->isGreaterThan('9999999999999999.9999')) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive customer deposit within the supported range.']);
            }
            $old = DB::table('customer_advance_receipts')
                ->where('submission_token', $data['submission_token'])->first();
            if ($old) {
                if ((int) $old->customer_id === (int) $locked->id
                    && (int) $old->currency_id === (int) $data['currency_id']
                    && (int) $old->destination_account_id === (int) $data['destination_account_id']
                    && $old->received_on === $data['received_on']
                    && $old->reference === $data['reference']
                    && BigDecimal::of((string) $old->amount)->isEqualTo($amount)) {
                    return $old;
                }
                throw ValidationException::withMessages(['submission_token' => 'Deposit token was used with different details.']);
            }
            if ($locked->type !== 'customer' || ! $locked->is_active
                || ! $locked->currencies()->whereKey($data['currency_id'])->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Select an active customer with this currency enabled.']);
            }

            $destination = FinancialAccount::whereKey($data['destination_account_id'])
                ->lockForUpdate()->firstOrFail();
            if (! in_array($destination->type, ['cashbox', 'bank', 'sarafi'], true)
                || ! $destination->is_active || (int) $destination->currency_id !== (int) $data['currency_id']) {
                throw ValidationException::withMessages(['destination_account_id' => 'Choose an active cashbox, bank or sarafi account in the deposit currency.']);
            }
            $cashLedger = DB::table('entity_ledger_accounts')->where([
                'entity_type' => $destination->type, 'entity_id' => $destination->id,
                'currency_id' => $data['currency_id'], 'purpose' => 'cash',
            ])->value('ledger_account_id');
            $advanceLedger = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $locked->id,
                'currency_id' => $data['currency_id'], 'purpose' => 'advance',
            ])->value('ledger_account_id');
            $cash = $cashLedger ? LedgerAccount::find($cashLedger) : null;
            $liability = $advanceLedger ? LedgerAccount::find($advanceLedger) : null;
            if (! $cash || ! $liability || ! $cash->is_active || ! $liability->is_active
                || $cash->is_group || $liability->is_group || $liability->type !== 'liability'
                || $cash->id === $liability->id) {
                throw ValidationException::withMessages(['customer_id' => 'Customer advance liability or destination cash ledger is not configured correctly.']);
            }

            DocumentSequence::firstOrCreate(['document_type' => 'customer_advance_receipt'], [
                'prefix' => 'CAR-', 'next_number' => 1, 'padding' => 6, 'is_active' => true,
            ]);
            $number = DocumentSequence::issue('customer_advance_receipt');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['received_on'],
                'reference' => $data['reference'],
                'description' => 'Customer deposit '.$locked->name,
                'currency_id' => $data['currency_id'],
                'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id' => $cash->id, 'debit' => (string) $amount, 'credit' => '0', 'memo' => 'Customer advance received'],
                ['ledger_account_id' => $liability->id, 'debit' => '0', 'credit' => (string) $amount, 'memo' => 'Customer advance liability'],
            ]);
            $this->journals->post($journal, $actorId);
            $money = MoneyTransaction::create([
                'submission_token' => $data['submission_token'], 'type' => 'receipt',
                'transaction_date' => $data['received_on'], 'source_account_id' => null,
                'destination_account_id' => $destination->id,
                'counterpart_ledger_account_id' => $liability->id,
                'currency_id' => $data['currency_id'],
                'amount' => (string) $amount, 'reference' => $data['reference'],
                'description' => 'Customer advance '.$locked->name.' / '.$number,
                'journal_entry_id' => $journal->id, 'created_by' => $actorId,
            ]);
            $id = DB::table('customer_advance_receipts')->insertGetId([
                'number' => $number, 'submission_token' => $data['submission_token'],
                'customer_id' => $locked->id, 'currency_id' => $data['currency_id'],
                'destination_account_id' => $destination->id,
                'advance_ledger_id' => $liability->id, 'money_transaction_id' => $money->id,
                'received_on' => $data['received_on'], 'amount' => (string) $amount,
                'reference' => $data['reference'], 'created_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ActivityLog::record('customer.advance.received', 'customer_advance_receipt', $id,
                'Customer deposit '.$number.' posted to liability and cash ledgers.',
                ['customer_id' => $locked->id, 'currency_id' => $data['currency_id'],
                 'amount' => (string) $amount, 'journal_id' => $journal->id]);
            return DB::table('customer_advance_receipts')->where('id', $id)->first();
        });
    }

    public function apply(int $receiptId, array $data, int $actorId): object
    {
        return DB::transaction(function () use ($receiptId, $data, $actorId) {
            $receipt = DB::table('customer_advance_receipts')
                ->where('id', $receiptId)->lockForUpdate()->first();
            abort_unless($receipt, 404);
            $value = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::Unnecessary);
            $old = DB::table('customer_advance_applications')
                ->where('submission_token', $data['submission_token'])->first();
            if ($old) {
                if ((int) $old->customer_advance_receipt_id === $receiptId
                    && (int) $old->trade_document_id === (int) $data['trade_document_id']
                    && $old->applied_on === $data['applied_on']
                    && BigDecimal::of((string) $old->amount)->isEqualTo($value)) {
                    return $old;
                }
                throw ValidationException::withMessages(['submission_token' => 'Application token was used for different details.']);
            }
            $document = TradeDocument::whereKey($data['trade_document_id'])
                ->lockForUpdate()->firstOrFail();
            if ($document->type !== 'receivable'
                || (int) $document->trade_party_id !== (int) $receipt->customer_id
                || (int) $document->currency_id !== (int) $receipt->currency_id) {
                throw ValidationException::withMessages(['trade_document_id' => 'Select a receivable for the same customer and currency.']);
            }
            if ($data['applied_on'] < $receipt->received_on
                || $data['applied_on'] < (string) $document->document_date) {
                throw ValidationException::withMessages(['applied_on' => 'Application cannot precede the deposit or invoice.']);
            }
            if (! $value->isPositive() || $value->isGreaterThan($this->remaining($receiptId))) {
                throw ValidationException::withMessages(['amount' => 'Customer advance has insufficient unapplied balance.']);
            }
            $outstanding = BigDecimal::of((string) $document->amount);
            foreach ($document->allocations as $allocation) {
                $outstanding = $outstanding->minus((string) $allocation->amount);
            }
            foreach ($document->credits as $credit) {
                $outstanding = $outstanding->minus((string) $credit->amount);
            }
            if ($value->isGreaterThan($outstanding)) {
                throw ValidationException::withMessages(['amount' => 'Application exceeds the unpaid invoice balance.']);
            }

            $receivable = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $receipt->customer_id,
                'currency_id' => $receipt->currency_id, 'purpose' => 'receivable',
            ])->value('ledger_account_id');
            $liability = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $receipt->customer_id,
                'currency_id' => $receipt->currency_id, 'purpose' => 'advance',
            ])->value('ledger_account_id');
            $receivableAccount = $receivable ? LedgerAccount::find($receivable) : null;
            $advanceAccount = $liability ? LedgerAccount::find($liability) : null;
            if (! $receivableAccount || ! $advanceAccount
                || (int) $receipt->advance_ledger_id !== (int) $liability
                || ! $receivableAccount->is_active || $receivableAccount->is_group
                || ! $advanceAccount->is_active || $advanceAccount->is_group
                || $receivableAccount->type !== 'asset' || $advanceAccount->type !== 'liability') {
                throw ValidationException::withMessages(['amount' => 'Customer receivable or advance ledger requires reconciliation.']);
            }

            DocumentSequence::firstOrCreate(['document_type' => 'customer_advance_application'], [
                'prefix' => 'CAA-', 'next_number' => 1, 'padding' => 6, 'is_active' => true,
            ]);
            $number = DocumentSequence::issue('customer_advance_application');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['applied_on'],
                'reference' => $receipt->number,
                'description' => 'Apply customer deposit '.$receipt->number.' to '.$document->number,
                'currency_id' => $receipt->currency_id,
                'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id' => $advanceAccount->id, 'debit' => (string) $value, 'credit' => '0', 'memo' => 'Release customer deposit liability'],
                ['ledger_account_id' => $receivableAccount->id, 'debit' => '0', 'credit' => (string) $value, 'memo' => 'Settle customer invoice'],
            ]);
            $this->journals->post($journal, $actorId);
            $id = DB::table('customer_advance_applications')->insertGetId([
                'number' => $number, 'submission_token' => $data['submission_token'],
                'customer_advance_receipt_id' => $receiptId,
                'trade_document_id' => $document->id, 'journal_entry_id' => $journal->id,
                'applied_on' => $data['applied_on'], 'amount' => (string) $value,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('trade_document_credits')->insert([
                'trade_document_id' => $document->id, 'sales_credit_note_id' => null,
                'supplier_debit_note_id' => null, 'supplier_advance_application_id' => null,
                'customer_advance_application_id' => $id,
                'credit_date' => $data['applied_on'], 'amount' => (string) $value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ActivityLog::record('customer.advance.applied', 'customer_advance_application', $id,
                'Customer deposit applied to receivable '.$document->number,
                ['customer_id' => $receipt->customer_id, 'currency_id' => $receipt->currency_id,
                 'amount' => (string) $value, 'journal_id' => $journal->id]);
            return DB::table('customer_advance_applications')->where('id', $id)->first();
        });
    }
    public function refund(int $receiptId, array $data, int $actorId): object
    {
        return DB::transaction(function () use ($receiptId, $data, $actorId) {
            $receipt = DB::table('customer_advance_receipts')
                ->where('id', $receiptId)->lockForUpdate()->first();
            abort_unless($receipt, 404);
            $value = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::Unnecessary);
            $old = DB::table('customer_advance_refunds')
                ->where('submission_token', $data['submission_token'])->first();
            if ($old) {
                if ((int) $old->customer_advance_receipt_id === $receiptId
                    && (int) $old->source_account_id === (int) $data['source_account_id']
                    && $old->refunded_on === $data['refunded_on']
                    && $old->reference === $data['reference']
                    && BigDecimal::of((string) $old->amount)->isEqualTo($value)) {
                    return $old;
                }
                throw ValidationException::withMessages(['submission_token' => 'Refund token was already used with different details.']);
            }
            if ($data['refunded_on'] < $receipt->received_on) {
                throw ValidationException::withMessages(['refunded_on' => 'Refund date cannot precede receipt date.']);
            }
            if (! $value->isPositive() || $value->isGreaterThan($this->remaining($receiptId))) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the unapplied and unrefunded customer deposit balance.']);
            }
            $source = FinancialAccount::whereKey($data['source_account_id'])
                ->lockForUpdate()->firstOrFail();
            if (! $source->is_active || ! in_array($source->type, ['cashbox','bank','sarafi'], true)
                || (int) $source->currency_id !== (int) $receipt->currency_id) {
                throw ValidationException::withMessages(['source_account_id' => 'Choose an active payment account in the original deposit currency.']);
            }
            $cashLedgerId = DB::table('entity_ledger_accounts')->where([
                'entity_type' => $source->type, 'entity_id' => $source->id,
                'currency_id' => $receipt->currency_id, 'purpose' => 'cash',
            ])->value('ledger_account_id');
            $advanceLedgerId = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $receipt->customer_id,
                'currency_id' => $receipt->currency_id, 'purpose' => 'advance',
            ])->value('ledger_account_id');
            $cash = $cashLedgerId ? LedgerAccount::find($cashLedgerId) : null;
            $advance = $advanceLedgerId ? LedgerAccount::find($advanceLedgerId) : null;
            if (! $cash || ! $advance || $advance->type !== 'liability'
                || (int) $advance->id !== (int) $receipt->advance_ledger_id
                || ! $cash->is_active || ! $advance->is_active
                || $cash->is_group || $advance->is_group || $cash->id === $advance->id) {
                throw ValidationException::withMessages(['source_account_id' => 'Refund cash or customer liability mapping needs reconciliation.']);
            }
            DocumentSequence::firstOrCreate(['document_type' => 'customer_advance_refund'], [
                'prefix' => 'CAF-', 'next_number' => 1, 'padding' => 6, 'is_active' => true,
            ]);
            $number = DocumentSequence::issue('customer_advance_refund');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['refunded_on'],
                'reference' => $data['reference'],
                'description' => 'Refund unallocated customer deposit '.$receipt->number,
                'currency_id' => $receipt->currency_id,
                'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id' => $advance->id, 'debit' => (string) $value, 'credit' => '0', 'memo' => 'Reverse unused customer advance liability'],
                ['ledger_account_id' => $cash->id, 'debit' => '0', 'credit' => (string) $value, 'memo' => 'Cash/bank returned to customer'],
            ]);
            $this->journals->post($journal, $actorId);
            $money = MoneyTransaction::create([
                'submission_token' => $data['submission_token'],
                'type' => 'payment', 'transaction_date' => $data['refunded_on'],
                'source_account_id' => $source->id, 'destination_account_id' => null,
                'counterpart_ledger_account_id' => $advance->id,
                'currency_id' => $receipt->currency_id,
                'amount' => (string) $value, 'reference' => $data['reference'],
                'description' => 'Customer deposit refund '.$receipt->number,
                'journal_entry_id' => $journal->id, 'created_by' => $actorId,
            ]);
            $id = DB::table('customer_advance_refunds')->insertGetId([
                'number' => $number, 'submission_token' => $data['submission_token'],
                'customer_advance_receipt_id' => $receiptId,
                'source_account_id' => $source->id,
                'money_transaction_id' => $money->id, 'journal_entry_id' => $journal->id,
                'refunded_on' => $data['refunded_on'], 'amount' => (string) $value,
                'reference' => $data['reference'], 'created_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ActivityLog::record('customer.advance.refunded', 'customer_advance_refund', $id,
                'Unused customer deposit refunded with posted liability and cash journal.',
                ['deposit_id' => $receiptId, 'amount' => (string) $value,
                 'currency_id' => $receipt->currency_id, 'journal_id' => $journal->id]);
            return DB::table('customer_advance_refunds')->where('id', $id)->first();
        });
    }

}
