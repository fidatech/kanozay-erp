<?php

namespace App\Services\Accounting;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Shareholder;
use App\Models\ShareholderTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShareholderTransactionService
{
    public function __construct(
        private readonly EntityAccountProvisioner $accounts,
        private readonly JournalPostingService $posting,
    ) {}

    public function post(Shareholder $shareholder, array $data, int $actorId): ShareholderTransaction
    {
        return DB::transaction(function () use ($shareholder, $data, $actorId) {
            $shareholder = Shareholder::query()->lockForUpdate()->findOrFail($shareholder->id);
            $existing = ShareholderTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $same = $existing->shareholder_id === $shareholder->id
                    && $existing->type === $data['type']
                    && $existing->currency_id === (int) $data['currency_id']
                    && $existing->financial_account_id === (int) $data['financial_account_id']
                    && BigDecimal::of((string) $existing->amount)->isEqualTo(BigDecimal::of((string) $data['amount']))
                    && $existing->transaction_date->toDateString() === $data['transaction_date'];
                if (! $same) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different transaction.']);
                }
                return $existing;
            }
            if (! $shareholder->is_active || ! $shareholder->currencies()->whereKey($data['currency_id'])->exists()) {
                throw ValidationException::withMessages(['currency_id' => 'Choose an active currency enabled for this shareholder.']);
            }

            $amount = BigDecimal::of((string) $data['amount'])->toScale(4);
            if (! $amount->isGreaterThan(0)) {
                throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
            }

            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($data['financial_account_id']);
            if (! $account->is_active || (int) $account->currency_id !== (int) $data['currency_id']) {
                throw ValidationException::withMessages(['financial_account_id' => 'Choose an active cash, bank, or Sarafi account in the transaction currency.']);
            }

            $partyLedgers = $this->accounts->provision('shareholder', $shareholder->id, $shareholder->name, (int) $data['currency_id']);
            $cashLedgerId = DB::table('entity_ledger_accounts')->where([
                'entity_type' => $account->type,
                'entity_id' => $account->id,
                'currency_id' => $data['currency_id'],
                'purpose' => 'cash',
            ])->value('ledger_account_id');
            if (! $cashLedgerId) {
                throw ValidationException::withMessages(['financial_account_id' => 'The selected financial account has no linked ledger account.']);
            }

            $partyLedger = $partyLedgers[$data['type'] === 'contribution' ? 'capital' : 'drawing'];
            $sequenceType = $data['type'] === 'contribution' ? 'shareholder_contribution' : 'shareholder_withdrawal';
            $prefix = $data['type'] === 'contribution' ? 'SHC-' : 'SHW-';
            DocumentSequence::firstOrCreate(
                ['document_type' => $sequenceType],
                ['prefix' => $prefix, 'next_number' => 1, 'padding' => 6, 'is_active' => true],
            );
            $number = DocumentSequence::issue($sequenceType);

            $journal = JournalEntry::create([
                'number' => 'JE-'.$number,
                'entry_date' => $data['transaction_date'],
                'reference' => $data['reference'] ?? $number,
                'description' => $data['description'],
                'status' => 'draft',
                'currency_id' => $data['currency_id'],
                'created_by' => $actorId,
            ]);
            $debit = $data['type'] === 'contribution' ? $cashLedgerId : $partyLedger->id;
            $credit = $data['type'] === 'contribution' ? $partyLedger->id : $cashLedgerId;
            $journal->lines()->create(['ledger_account_id' => $debit, 'debit' => (string) $amount, 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $credit, 'debit' => '0', 'credit' => (string) $amount]);
            $this->posting->post($journal, $actorId);

            $transaction = ShareholderTransaction::create([
                'number' => $number,
                'idempotency_key' => $data['idempotency_key'],
                'shareholder_id' => $shareholder->id,
                'type' => $data['type'],
                'transaction_date' => $data['transaction_date'],
                'currency_id' => $data['currency_id'],
                'financial_account_id' => $account->id,
                'amount' => (string) $amount,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'journal_entry_id' => $journal->id,
                'created_by' => $actorId,
            ]);
            ActivityLog::record('shareholder.transaction_posted', 'shareholder_transaction', $transaction->id, ucfirst($data['type']).' '.$number.' posted for '.$shareholder->name);

            return $transaction->load(['currency', 'financialAccount', 'journal']);
        });
    }
}
