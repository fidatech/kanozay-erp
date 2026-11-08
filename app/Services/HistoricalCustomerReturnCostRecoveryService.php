<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SalesReturnQuarantine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historical source recovery for a customer credit note whose product was
 * ALREADY physically QC-released and restocked without its corresponding
 * original-currency inventory asset / COGS reversal journal.
 *
 * This never books a second credit note, sale, refund, QC decision, physical
 * movement or inventory quantity. Invoices and original COGS must be real.
 */
final class HistoricalCustomerReturnCostRecoveryService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_customer_return'=>$message]);
    }

    private function validJournal(int $id,int $currencyId,string $date,string $description): object
    {
        $journal=DB::table('journal_entries')->where('id',$id)->first();
        if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==$currencyId ||
            (string)$journal->entry_date!==$date ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists())
            $this->fail('The original '.$description.' journal is absent, reversed, unposted, in another currency or on a different original date.');
        return $journal;
    }

    private function net(int $journalId,int $ledgerId): BigDecimal
    {
        $result=BigDecimal::zero();
        foreach(DB::table('journal_lines')->where('journal_entry_id',$journalId)
            ->where('ledger_account_id',$ledgerId)->get(['debit','credit']) as $line){
            $result=$result->plus((string)$line->debit)->minus((string)$line->credit);
        }
        return $result;
    }

    /** Revalidate source, QC, physical restock, original revenue/credit and COGS. */
    private function source(int $caseId): array
    {
        $case=DB::table('finance_historical_correction_cases')->where('id',$caseId)
            ->lockForUpdate()->first();
        abort_unless($case,404);
        if($case->case_type!=='released_return_missing_cost' ||
            $case->status!=='approved' ||
            !$case->reviewed_by ||
            (int)$case->requested_by===(int)$case->reviewed_by)
            $this->fail('Independently approved historical QC-return cost correction case is required.');
        $original=app(HistoricalFinanceCorrectionService::class)
            ->source('released_return_missing_cost',(int)$case->source_id);
        if(!hash_equals((string)$case->source_fingerprint,(string)$original['fingerprint']))
            $this->fail('Original approved customer credit note, physical restock or QC decision changed. Stop; open a new source-evidence review.');
        $qc=SalesReturnQuarantine::whereKey($case->source_id)->lockForUpdate()->firstOrFail();
        $date=$qc->decided_on?->format('Y-m-d');
        if($qc->status!=='released' || !$date ||
            $date!==(string)$case->source_date ||
            !$qc->decided_by || !$qc->registered_by ||
            (int)$qc->registered_by===(int)$qc->decided_by ||
            !$qc->quality_reference || !$qc->decision_notes ||
            !$qc->released_warehouse_id || (int)$qc->quantity_packages<1 ||
            $date>now()->toDateString())
            $this->fail('Original source must show genuine independently QC-approved, physically saleable restock in a named warehouse, with signed approval and original date.');

        $movements=DB::table('finished_good_warehouse_movements')
            ->where('production_batch_id',$qc->production_batch_id)
            ->where('type','customer_return')
            ->where('reference','Quarantined customer return QC '.$qc->id)->get();
        if($movements->count()!==1 ||
            (int)$movements->first()->destination_warehouse_id!==(int)$qc->released_warehouse_id ||
            $movements->first()->source_warehouse_id!==null ||
            (int)$movements->first()->quantity_packages!==(int)$qc->quantity_packages ||
            (string)$movements->first()->occurred_on!==$date ||
            (int)$movements->first()->created_by!==(int)$qc->decided_by)
            $this->fail('Missing or inconsistent exact original QC customer-return warehouse movement; this accounting correction cannot create or assume stock.');
        if(DB::table('production_return_cost_postings')
            ->where('sales_return_quarantine_id',$qc->id)->exists())
            $this->fail('This released source return already has an inventory asset/COGS recovery journal.');

        $credit=DB::table('sales_credit_note_lines')
            ->where('id',$qc->sales_credit_note_line_id)->first();
        $note=$credit?DB::table('sales_credit_notes')
            ->where('id',$credit->sales_credit_note_id)->first():null;
        $sale=$credit?DB::table('sales_invoice_lines')
            ->where('id',$credit->sales_invoice_line_id)->first():null;
        $invoice=$sale?DB::table('sales_invoices')
            ->where('id',$sale->sales_invoice_id)->first():null;
        if(!$credit || !$note || !$sale || !$invoice ||
            $invoice->status!=='posted' ||
            (int)$note->sales_invoice_id!==(int)$invoice->id ||
            (int)$note->trade_document_id!==(int)$invoice->trade_document_id ||
            (int)$credit->production_batch_id!==(int)$qc->production_batch_id ||
            (int)$sale->production_batch_id!==(int)$qc->production_batch_id ||
            (int)$credit->finished_good_id!==(int)$sale->finished_good_id ||
            (int)$credit->quantity_packages!==(int)$qc->quantity_packages ||
            (int)$credit->quantity_packages<1 ||
            (int)$credit->quantity_packages>(int)$sale->quantity_packages ||
            (bool)$credit->restocked ||
            (string)$note->currency_code!==(string)$invoice->currency_code ||
            (string)$invoice->invoice_date>(string)$note->credit_date ||
            (string)$note->credit_date>$date)
            $this->fail('Original posted credit note, customer invoice, line, sale quantity, batch, currency or credit/QC chronology is inconsistent. Direct-restock legacy credits are excluded.');

        $credited=BigDecimal::of((string)$credit->unit_price)
            ->multipliedBy((string)$credit->quantity_packages)
            ->toScale(4,RoundingMode::HalfUp);
        if(!$credited->isEqualTo((string)$credit->line_total) ||
            !BigDecimal::of((string)$sale->unit_price)
                ->isEqualTo((string)$credit->unit_price))
            $this->fail('Original credit-note customer price or line total differs from its genuine invoice source.');
        $allNotes=DB::table('sales_credit_note_lines')
            ->where('sales_invoice_line_id',$sale->id)->get(['quantity_packages']);
        if($allNotes->sum('quantity_packages')>(int)$sale->quantity_packages)
            $this->fail('Total original customer credits exceed source invoiced physical quantity.');
        $customerLedger=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'customer','entity_id'=>$invoice->customer_id,
            'currency_id'=>$invoice->currency_id,'purpose'=>'receivable',
        ])->value('ledger_account_id');
        if(!$customerLedger)
            $this->fail('Original customer receivable GL mapping is missing.');
        $this->validJournal((int)$invoice->journal_entry_id,(int)$invoice->currency_id,
            (string)$invoice->invoice_date,'customer revenue');
        $this->validJournal((int)$note->journal_entry_id,(int)$invoice->currency_id,
            (string)$note->credit_date,'customer credit');
        $document=DB::table('trade_documents')
            ->where('id',$invoice->trade_document_id)->first();
        if(!$document || $document->type!=='receivable' ||
            (int)$document->trade_party_id!==(int)$invoice->customer_id ||
            (int)$document->currency_id!==(int)$invoice->currency_id ||
            (int)$document->journal_entry_id!==(int)$invoice->journal_entry_id ||
            !BigDecimal::of((string)$document->amount)
                ->isEqualTo((string)$invoice->total) ||
            !$this->net((int)$invoice->journal_entry_id,(int)$customerLedger)
                ->isEqualTo((string)$invoice->total) ||
            !$this->net((int)$note->journal_entry_id,(int)$customerLedger)
                ->isEqualTo(BigDecimal::of((string)$note->total)->negated()))
            $this->fail('Original invoiced receivable and posted customer credit journal do not reconcile to the original customer amount.');

        $batch=DB::table('production_batches')->where('id',$qc->production_batch_id)->first();
        $source=DB::table('production_invoice_cogs_postings')
            ->where('sales_invoice_line_id',$sale->id)->first();
        if(!$batch || $batch->status!=='released' || !$source ||
            (int)$batch->finished_good_id!==(int)$sale->finished_good_id ||
            (int)$source->quantity_packages!==(int)$sale->quantity_packages)
            $this->fail('Previously posted original customer COGS for this exact QC-released production batch and sold quantity is missing.');
        $cost=DB::table('production_batch_cost_postings')
            ->where('id',$source->production_batch_cost_posting_id)->first();
        if(!$cost || (int)$cost->production_batch_id!==(int)$qc->production_batch_id ||
            (string)$cost->accounting_date>(string)$invoice->invoice_date ||
            (int)($case->source_currency_id??0)!==(int)$cost->currency_id)
            $this->fail('Previously capitalized original customer sale batch cost is invalid or uses another source currency.');
        $posted=app(FinishedGoodsCostPostingService::class)
            ->assertCapitalized((int)$batch->id,(string)$invoice->invoice_date);
        if((int)$posted->id!==(int)$cost->id)
            $this->fail('Original financial capitalization changed since the authentic customer sale.');

        $policy=app(ManufacturingLedgerPolicyService::class)->approved((int)$cost->currency_id);
        if((int)$policy->id!==(int)$cost->manufacturing_cost_ledger_policy_id)
            $this->fail('Original manufacturing COGS and finished-goods asset policy is no longer approved.');
        $this->validJournal((int)$source->journal_entry_id,(int)$cost->currency_id,
            (string)$invoice->invoice_date,'original source COGS');
        if(!$this->net((int)$source->journal_entry_id,(int)$policy->cogs_ledger_id)
                ->isEqualTo((string)$source->amount) ||
            !$this->net((int)$source->journal_entry_id,(int)$policy->finished_inventory_ledger_id)
                ->isEqualTo(BigDecimal::of((string)$source->amount)->negated()))
            $this->fail('Original COGS debit and finished-goods asset credit do not equal the exact original invoice cost.');

        $previous=DB::table('production_return_cost_postings')
            ->where('production_invoice_cogs_posting_id',$source->id)->get();
        $usedQty=0;$usedCost=BigDecimal::zero();
        $lastReturnDate=null;
        foreach($previous as $row){
            $priorQc=DB::table('sales_return_quarantines')
                ->where('id',$row->sales_return_quarantine_id)->first();
            $journal=DB::table('journal_entries')->where('id',$row->journal_entry_id)->first();
            if(!$priorQc || $priorQc->status!=='released' || !$journal ||
                $journal->status!=='posted' || $journal->reversal_of_id ||
                (int)$journal->currency_id!==(int)$cost->currency_id ||
                (string)$journal->entry_date!==(string)$priorQc->decided_on ||
                DB::table('journal_entries')->where('reversal_of_id',$journal->id)->exists())
                $this->fail('Earlier customer returns for this invoice have unreconciled or reversed original-currency recovery journals.');
            if($lastReturnDate===null || $journal->entry_date>$lastReturnDate)
                $lastReturnDate=$journal->entry_date;
            $usedQty+=(int)$row->quantity_packages;
            $usedCost=$usedCost->plus((string)$row->amount);
        }
        if($lastReturnDate && $date<$lastReturnDate)
            $this->fail('Original QC date precedes another already costed return; this case requires separate chronological source reconciliation.');
        $laterSale=DB::table('production_invoice_cogs_postings as sold')
            ->join('journal_entries as journal','journal.id','=','sold.journal_entry_id')
            ->where('sold.production_batch_cost_posting_id',$cost->id)
            ->where('journal.entry_date','>',$date)->exists();
        $laterNonSale=DB::table('finance_historical_dispatch_cost_lines as charged')
            ->join('journal_entries as journal','journal.id','=','charged.journal_entry_id')
            ->where('charged.production_batch_cost_posting_id',$cost->id)
            ->where('journal.entry_date','>',$date)->exists();
        if($laterSale || $laterNonSale)
            $this->fail('Another batch sale or non-sale cost journal postdates the historical QC return. A separate chronological inventory adjustment is required.');

        $qty=(int)$qc->quantity_packages;
        $originalCost=BigDecimal::of((string)$source->amount);
        if($usedQty<0 || $usedQty+$qty>(int)$source->quantity_packages ||
            $usedCost->isNegative() || $usedCost->isGreaterThanOrEqualTo($originalCost))
            $this->fail('Returned packages or prior QC cost restorations exceed the original invoiced COGS.');
        $amount=$usedQty+$qty===(int)$source->quantity_packages
            ?$originalCost->minus($usedCost)
            :$originalCost->multipliedBy((string)$qty)
                ->dividedBy((string)$source->quantity_packages,4,RoundingMode::HalfUp);
        if(!$amount->isPositive() ||
            $amount->isGreaterThan($originalCost->minus($usedCost)) ||
            $amount->isGreaterThan('9999999999999999.9999'))
            $this->fail('Return original-currency asset/COGS recovery cannot exceed its eligible original COGS.');
        $period=app(FinancePeriodClosingService::class);
        $period->assertOpenDate($date);
        $fingerprint=hash('sha256',json_encode([
            'original_case'=>$case->source_fingerprint,
            'qc_id'=>$qc->id,'date'=>$date,
            'source_invoice_line'=>$sale->id,
            'source_cogs'=>[$source->id,$source->production_batch_cost_posting_id,
                $source->journal_entry_id,$source->amount,$source->quantity_packages],
            'policy'=>[$policy->id,$policy->currency_id,
                $policy->cogs_ledger_id,$policy->finished_inventory_ledger_id],
            'original_credit'=>[$note->id,$note->journal_entry_id,$credit->id],
            'prior_returns'=>$previous->toArray(),
            'expected_recovery'=>(string)$amount,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));

        return [
            'case'=>$case,'qc'=>$qc,'cost'=>$cost,'source'=>$source,
            'date'=>$date,'currency_id'=>(int)$cost->currency_id,
            'asset_id'=>(int)$policy->finished_inventory_ledger_id,
            'cogs_id'=>(int)$policy->cogs_ledger_id,
            'quantity'=>$qty,'original_amount'=>(string)$originalCost->toScale(4),
            'amount'=>(string)$amount->toScale(4,RoundingMode::HalfUp),
            'fingerprint'=>$fingerprint,
        ];
    }

    private function unchanged(object $plan,array $s): void
    {
        if((int)$plan->sales_return_quarantine_id!==(int)$s['qc']->id ||
            (int)$plan->production_invoice_cogs_posting_id!==(int)$s['source']->id ||
            (int)$plan->currency_id!==$s['currency_id'] ||
            (int)$plan->finished_inventory_ledger_id!==$s['asset_id'] ||
            (int)$plan->cogs_ledger_id!==$s['cogs_id'] ||
            (int)$plan->quantity_packages!==$s['quantity'] ||
            (string)$plan->original_qc_date!==$s['date'] ||
            (string)$plan->posting_date!==$s['date'] ||
            !BigDecimal::of((string)$plan->original_cogs_amount)
                ->isEqualTo($s['original_amount']) ||
            !BigDecimal::of((string)$plan->recoverable_original_cost)
                ->isEqualTo($s['amount']) ||
            !hash_equals((string)$plan->source_fingerprint,(string)$s['fingerprint']))
            $this->fail('Approved original QC credit, posted COGS, source currency, recovered amount, warehouse evidence or period changed after independent finance review.');
    }

    private function independent(object $case,SalesReturnQuarantine $qc,int $actorId,
        ?object $plan=null): void
    {
        $actors=[
            (int)$case->requested_by,(int)$case->reviewed_by,
            (int)$qc->registered_by,(int)$qc->decided_by,
        ];
        if($plan){
            $actors[]=(int)$plan->submitted_by;
            $actors[]=(int)$plan->approved_by;
        }
        if(in_array($actorId,$actors,true))
            $this->fail('Finance staff must differ from the credit-note registrar, independent QC approver, historical case requester/reviewer and earlier plan actors.');
    }

    public function submit(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $s=$this->source($caseId);
            $this->independent($s['case'],$s['qc'],$actorId);
            if(DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('sales_return_quarantine_id',$s['qc']->id)->exists())
                $this->fail('This QC return already has an original-cost correction plan. Resubmission requires an audited amendment.');
            $id=DB::table('finance_historical_customer_return_cost_recoveries')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'sales_return_quarantine_id'=>$s['qc']->id,
                'production_invoice_cogs_posting_id'=>$s['source']->id,
                'currency_id'=>$s['currency_id'],
                'finished_inventory_ledger_id'=>$s['asset_id'],
                'cogs_ledger_id'=>$s['cogs_id'],
                'status'=>'submitted','original_qc_date'=>$s['date'],
                'posting_date'=>$s['date'],
                'quantity_packages'=>$s['quantity'],
                'original_cogs_amount'=>$s['original_amount'],
                'recoverable_original_cost'=>$s['amount'],
                'source_fingerprint'=>$s['fingerprint'],
                'source_evidence_reference'=>trim($data['source_evidence_reference']),
                'source_reconciliation_notes'=>trim($data['source_reconciliation_notes']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_customer_return_cost.submitted',
                'finance_historical_customer_return_cost_recovery',$id,
                'Source-linked original QC-released return submitted for independent finished-inventory/COGS restoration; no physical or GL movement.');
            return DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$id)->first();
        });
    }

    public function approve(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $plan=DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='submitted')
                $this->fail('Only a submitted and unposted historical QC return plan can be reviewed.');
            $s=$this->source($caseId);
            $this->independent($s['case'],$s['qc'],$actorId,$plan);
            $this->unchanged($plan,$s);
            $approved=$data['decision']==='approve';
            DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$plan->id)->update([
                    'status'=>$approved?'approved':'rejected',
                    'approval_reference'=>trim($data['approval_reference']),
                    'approval_notes'=>trim($data['approval_notes']),
                    'approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_customer_return_cost.'.
                    ($approved?'approved':'rejected'),
                'finance_historical_customer_return_cost_recovery',$plan->id,
                'Independent original-currency QC-return asset recovery decision recorded; no GL or warehouse movements.');
            return DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$plan->id)->first();
        });
    }

    public function post(int $caseId,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$actorId){
            $plan=DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='approved' || $plan->journal_entry_id!==null)
                $this->fail('Only an independently approved and unposted historical QC inventory recovery can execute.');
            $s=$this->source($caseId);
            $this->independent($s['case'],$s['qc'],$actorId,$plan);
            $this->unchanged($plan,$s);
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate($s['date']);
            DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$plan->id)->update([
                    'status'=>'posting','updated_at'=>now(),
                ]);
            // This dedicated temporary posting state is accepted only by the
            // original COGS recovery engine. Both records and journal are in
            // this transaction and roll back atomically on any failure.
            $posted=app(FinishedGoodsCostPostingService::class)
                ->postReturnRecovery($s['qc'],$s['date'],$actorId,$caseId);
            if((int)$posted->production_invoice_cogs_posting_id!==(int)$s['source']->id ||
                (int)$posted->quantity_packages!==$s['quantity'] ||
                !BigDecimal::of((string)$posted->amount)->isEqualTo($s['amount']))
                $this->fail('Posted original-Cogs return journal differs from the independently approved source recovery amount or quantity.');
            $journal=DB::table('journal_entries')
                ->where('id',$posted->journal_entry_id)->first();
            if(!$journal || $journal->status!=='posted' ||
                (int)$journal->currency_id!==$s['currency_id'] ||
                (string)$journal->entry_date!==$s['date'] ||
                !$this->net((int)$journal->id,$s['asset_id'])
                    ->isEqualTo($s['amount']) ||
                !$this->net((int)$journal->id,$s['cogs_id'])
                    ->isEqualTo(BigDecimal::of($s['amount'])->negated()))
                $this->fail('Historical return GL must debit original finished-goods asset and reverse only its documented original-currency COGS.');
            DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$plan->id)->update([
                    'status'=>'posted','journal_entry_id'=>$journal->id,
                    'posted_by'=>$actorId,'posted_at'=>now(),
                    'updated_at'=>now(),
                ]);
            DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->update([
                    'status'=>'resolved','open_key'=>null,
                    'source_currency_id'=>$s['currency_id'],
                    'resolution_reference'=>$plan->approval_reference,
                    'resolution_notes'=>'Verified existing independently QC-released customer restock, genuine posted credit note and invoice; original-currency COGS recovered '.
                        $s['amount'].' without creating physical packages, credit notes or refunds. '.
                        $plan->source_reconciliation_notes,
                    'executed_by'=>$actorId,'executed_at'=>now(),
                    'updated_at'=>now(),
                ]);
            DB::table('finance_historical_correction_events')->insert([
                'finance_historical_correction_case_id'=>$caseId,
                'action'=>'resolved','source_reference'=>$s['case']->source_reference,
                'source_fingerprint'=>$s['case']->source_fingerprint,
                'approval_reference'=>$plan->approval_reference,
                'notes'=>'Original QC return #'.$s['qc']->id.' source cost '.
                    $s['amount'].' currency #'.$s['currency_id'].
                    ' restored to finished asset, COGS reversed in '.$journal->number.
                    '; original customer note, QC approval and warehouse stock unchanged. '.
                    $plan->source_evidence_reference,
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_customer_return_cost.posted',
                'finance_historical_customer_return_cost_recovery',$plan->id,
                'Original-currency independent QC customer return asset restored and original COGS reversed, no duplicate stock.',
                ['quarantine_id'=>$s['qc']->id,'journal_id'=>$journal->id,
                    'original_cogs_id'=>$s['source']->id,'amount'=>$s['amount']]);
            return DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('id',$plan->id)->first();
        });
    }
}
