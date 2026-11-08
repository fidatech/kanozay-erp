<?php

namespace App\Services\Accounting;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Shareholder;
use App\Models\ShareholderTransaction;
use App\Models\MoneyTransaction;
use App\Services\FinancePeriodClosingService;
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
            // Source-currency shareholder cash movements participate in the
            // same month-close/posting lock as journals and bank reconciliation.
            app(FinancePeriodClosingService::class)->holdPostingGate();
            $shareholder = Shareholder::query()->lockForUpdate()->findOrFail($shareholder->id);
            $existing = ShareholderTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $same = $existing->shareholder_id === $shareholder->id
                    && $existing->type === $data['type']
                    && $existing->currency_id === (int) $data['currency_id']
                    && $existing->financial_account_id === (int) $data['financial_account_id']
                    && BigDecimal::of((string) $existing->amount)->isEqualTo(BigDecimal::of((string) $data['amount']))
                    && $existing->transaction_date->toDateString() === $data['transaction_date']
                    && (string)($existing->reference??'') === (string)($data['reference']??'')
                    && $existing->description === $data['description']
                    && (int)$existing->created_by===$actorId;
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
            if($data['type']==='withdrawal'){
                if(empty(trim((string)($data['reference']??'')))){
                    throw ValidationException::withMessages(['reference'=>'Capital withdrawal requires a signed authorization reference.']);
                }
                $cap=BigDecimal::zero();$drawn=BigDecimal::zero();
                foreach(['capital'=>$partyLedgers['capital'],'drawing'=>$partyLedgers['drawing']] as $purpose=>$equity){
                    $lines=DB::table('journal_lines as detail')
                        ->join('journal_entries as journal',
                           'journal.id','=','detail.journal_entry_id')
                        ->where('journal.status','posted')
                        ->where('journal.currency_id',$data['currency_id'])
                        ->whereDate('journal.entry_date','<=',$data['transaction_date'])
                        ->where('detail.ledger_account_id',$equity->id)
                        ->select('detail.debit','detail.credit')->get();
                    foreach($lines as $item){
                        if($purpose==='capital')
                            $cap=$cap->plus((string)$item->credit)->minus((string)$item->debit);
                        else
                            $drawn=$drawn->plus((string)$item->debit)->minus((string)$item->credit);
                    }
                }
                if($cap->minus($drawn)->isLessThan($amount)){
                    throw ValidationException::withMessages([
                        'amount'=>'Posted paid-in capital after prior drawings is insufficient for this withdrawal in the selected currency and transaction date. Use the separately approved profit distribution process where applicable.',
                    ]);
                }
            }
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

            // Bank reconciliation and cashbook must see the physical money
            // movement, not just a GL journal and a shareholder subledger.
            MoneyTransaction::create([
                'submission_token'=>$data['idempotency_key'],
                'type'=>$data['type']==='contribution'?'receipt':'payment',
                'transaction_date'=>$data['transaction_date'],
                'source_account_id'=>$data['type']==='withdrawal'?$account->id:null,
                'destination_account_id'=>$data['type']==='contribution'?$account->id:null,
                'counterpart_ledger_account_id'=>$partyLedger->id,
                'currency_id'=>$data['currency_id'],
                'amount'=>(string)$amount,
                'reference'=>$data['reference']??$number,
                'description'=>$data['description'],
                'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
            ]);

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
