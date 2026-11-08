<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historical QC holds are documentary only. Independently scrapped goods
 * remain outside saleable inventory: reclassify their ALREADY recognized
 * original-factory-currency sale COGS to an approved returned-goods loss
 * expense, without crediting inventory or recreating any customer document.
 */
final class HistoricalCustomerReturnDispositionService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_return_disposition'=>$message]);
    }

    private function journal(int $id,int $currencyId,string $date): object
    {
        $journal=DB::table('journal_entries')->where('id',$id)->first();
        if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==$currencyId ||
            (string)$journal->entry_date!==$date ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists())
            $this->fail('An original sale, customer credit or COGS source journal is missing, reversed, unposted, in another currency or on an inconsistent original date.');
        return $journal;
    }

    private function net(int $journalId,int $ledgerId): BigDecimal
    {
        $sum=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$journalId)
            ->where('ledger_account_id',$ledgerId)
            ->get(['debit','credit']) as $line)
            $sum=$sum->plus((string)$line->debit)->minus((string)$line->credit);
        return $sum;
    }

    private function lossLedger(int $id,object $policy): void
    {
        $ledger=DB::table('ledger_accounts')->where('id',$id)->first();
        if(!$ledger || !$ledger->is_active || $ledger->is_group ||
            $ledger->type!=='expense' ||
            in_array($id,[
                (int)$policy->cogs_ledger_id,
                (int)$policy->material_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->finished_inventory_ledger_id,
            ],true) ||
            DB::table('entity_ledger_accounts')->where('ledger_account_id',$id)->exists())
            $this->fail('Choose an independently approved active scrapped-returns loss expense ledger, not the original sales COGS, inventory asset or customer/supplier account.');
    }

    private function independent(array $s,int $actorId,?object $plan=null): void
    {
        $actors=[
            (int)$s['case']->requested_by,
            (int)$s['case']->reviewed_by,
            (int)$s['qc']->registered_by,
            (int)($s['qc']->decided_by??0),
            (int)($s['note']->created_by??0),
        ];
        if($plan){
            $actors[]=(int)$plan->submitted_by;
            $actors[]=(int)($plan->approved_by??0);
        }
        if(in_array($actorId,$actors,true))
            $this->fail('Historical finance proposer, independent reviewer and final GL poster must differ from source case staff, the original credit author and QC registrar/decider.');
    }

    /**
     * Every review starts with the original independent case and rechecks
     * genuine customer credit, physical dispatch and QC disposition. Source
     * cost is required ONLY if QC actually scrapped the returned packages.
     */
    private function source(int $caseId): array
    {
        $case=DB::table('finance_historical_correction_cases')
            ->where('id',$caseId)->lockForUpdate()->first();
        abort_unless($case,404);
        if($case->case_type!=='customer_return_disposition' ||
            $case->status!=='approved' || !$case->reviewed_by ||
            (int)$case->requested_by===(int)$case->reviewed_by)
            $this->fail('A separately approved historical customer-return disposition case is required.');

        $original=app(HistoricalFinanceCorrectionService::class)
            ->source('customer_return_disposition',(int)$case->source_id);
        if(!hash_equals((string)$case->source_fingerprint,
                (string)$original['fingerprint']))
            $this->fail('The source customer credit, QC status, original COGS or warehouse evidence changed after case approval. Submit a new source case.');

        $qc=DB::table('sales_return_quarantines')
            ->where('id',$case->source_id)->lockForUpdate()->first();
        if(!$qc || !in_array($qc->status,['quarantined','scrapped'],true) ||
            !$qc->registered_by || (int)$qc->quantity_packages<1 ||
            $qc->released_warehouse_id!==null ||
            DB::table('production_return_cost_postings')
                ->where('sales_return_quarantine_id',$qc->id)->exists())
            $this->fail('Only held or signed scrapped returned packages with no previously restored saleable asset can be reviewed.');

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
            (int)$credit->sales_invoice_line_id!==(int)$sale->id ||
            (int)$credit->production_batch_id!==(int)$qc->production_batch_id ||
            (int)$sale->production_batch_id!==(int)$qc->production_batch_id ||
            (int)$credit->finished_good_id!==(int)$sale->finished_good_id ||
            (int)$qc->quantity_packages!==(int)$credit->quantity_packages ||
            (int)$credit->quantity_packages>(int)$sale->quantity_packages ||
            (bool)$credit->restocked ||
            (string)$note->currency_code!==(string)$invoice->currency_code ||
            (string)$note->credit_date<(string)$invoice->invoice_date)
            $this->fail('Original customer invoice, sales credit, batch, original price, refund and received quantity must reconcile without direct restock.');
        $originalPrice=BigDecimal::of((string)$sale->unit_price);
        if(!$originalPrice->isEqualTo((string)$credit->unit_price) ||
            !$originalPrice->multipliedBy((string)$credit->quantity_packages)
                ->toScale(4,RoundingMode::HalfUp)
                ->isEqualTo((string)$credit->line_total))
            $this->fail('Original customer return credit must use the authentic sale unit price and exact returned package quantity.');
        $credited=DB::table('sales_credit_note_lines')
            ->where('sales_invoice_line_id',$sale->id)->get();
        if($credited->sum('quantity_packages')>(int)$sale->quantity_packages)
            $this->fail('Total historical customer credits exceed the genuine original sold quantity.');
        $noteLines=DB::table('sales_credit_note_lines')
            ->where('sales_credit_note_id',$note->id)->get();
        $noteAmount=BigDecimal::zero();
        foreach($noteLines as $line)$noteAmount=$noteAmount->plus((string)$line->line_total);
        if(!$noteAmount->isPositive() ||
            !$noteAmount->isEqualTo((string)$note->total))
            $this->fail('Posted original customer credit-note line total does not reconcile to its header.');

        $physical=DB::table('finished_good_dispatch_lines as line')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','line.finished_good_dispatch_id')
            ->where('line.finished_good_dispatch_id',$sale->dispatch_id)
            ->get(['line.production_batch_id','line.finished_good_id',
                'line.quantity_packages','dispatch.dispatch_date']);
        if($physical->count()!==1 ||
            (int)$physical->first()->production_batch_id!==(int)$sale->production_batch_id ||
            (int)$physical->first()->finished_good_id!==(int)$sale->finished_good_id ||
            (int)$physical->first()->quantity_packages!==(int)$sale->quantity_packages ||
            (string)$physical->first()->dispatch_date!==(string)$invoice->invoice_date ||
            DB::table('sales_invoice_lines')
                ->where('dispatch_id',$sale->dispatch_id)
                ->where('id','!=',$sale->id)->exists())
            $this->fail('Original credit must reference a unique, genuine, quantity-matched, posted customer warehouse dispatch.');

        $warehouseReturns=DB::table('finished_good_warehouse_movements')
            ->where('production_batch_id',$qc->production_batch_id)
            ->where('type','customer_return')
            ->where('reference','Quarantined customer return QC '.$qc->id);
        if($warehouseReturns->exists())
            $this->fail('This credit/QC source already has a physical return-to-stock movement. Held and scrapped customer returns cannot restore or remove saleable inventory.');
        if($qc->status==='quarantined' &&
            ($qc->decided_by!==null || $qc->decided_on!==null))
            $this->fail('A still-quarantined return cannot have a signed final QC disposition. Finish independent physical QC before loss accounting.');
        if($qc->status==='scrapped' &&
            (!$qc->decided_by || !$qc->decided_on ||
             !$qc->quality_reference || !$qc->decision_notes ||
             (int)$qc->registered_by===(int)$qc->decided_by))
            $this->fail('Actual scrapping requires a signed, independently approved QC loss/disposal decision with original date and explanation.');

        $receivable=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'customer','entity_id'=>$invoice->customer_id,
            'currency_id'=>$invoice->currency_id,'purpose'=>'receivable',
        ])->value('ledger_account_id');
        $document=DB::table('trade_documents')
            ->where('id',$invoice->trade_document_id)->first();
        if(!$receivable || !$document ||
            $document->type!=='receivable' ||
            (int)$document->trade_party_id!==(int)$invoice->customer_id ||
            (int)$document->currency_id!==(int)$invoice->currency_id ||
            (int)$document->journal_entry_id!==(int)$invoice->journal_entry_id ||
            !BigDecimal::of((string)$document->amount)
                ->isEqualTo((string)$invoice->total))
            $this->fail('Original genuine customer receivable and posted sale document are missing or mismatched.');

        $this->journal((int)$invoice->journal_entry_id,
            (int)$invoice->currency_id,(string)$invoice->invoice_date);
        $this->journal((int)$note->journal_entry_id,
            (int)$invoice->currency_id,(string)$note->credit_date);
        if(!$this->net((int)$invoice->journal_entry_id,(int)$receivable)
                ->isEqualTo((string)$invoice->total) ||
            !$this->net((int)$note->journal_entry_id,(int)$receivable)
                ->isEqualTo(BigDecimal::of((string)$note->total)->negated()))
            $this->fail('Genuine customer invoice receivable debit and credit-note receivable credit GL must be posted, balanced and match original customer amounts.');

        $income=DB::table('journal_lines as row')
            ->join('ledger_accounts as ledger','ledger.id','=','row.ledger_account_id')
            ->where('row.journal_entry_id',$invoice->journal_entry_id)
            ->where('ledger.type','income')
            ->get(['row.ledger_account_id','row.debit','row.credit']);
        if($income->count()!==1 ||
            !BigDecimal::of((string)$income->first()->credit)
                ->minus((string)$income->first()->debit)
                ->isEqualTo((string)$invoice->total) ||
            !$this->net((int)$note->journal_entry_id,
                (int)$income->first()->ledger_account_id)
                ->isEqualTo((string)$note->total))
            $this->fail('The original customer credit must reverse the same authentic invoice income ledger for its exact credited amount.');

        $isScrap=$qc->status==='scrapped';
        $date=$isScrap?(string)$qc->decided_on:(string)$note->credit_date;
        if($date<(string)$note->credit_date ||
            $date<(string)$invoice->invoice_date ||
            $date>now()->toDateString())
            $this->fail('The original QC scrap/credit review date is inconsistent with the customer sale, customer credit or current accounting date.');

        $returnData=[
            'case'=>$case,'qc'=>$qc,'note'=>$note,
            'sale'=>$sale,'credit'=>$credit,
            'date'=>$date,
            'treatment'=>$isScrap?'scrap_cogs_reclass':'quarantine_hold',
            'currency_id'=>null,'source_cogs_id'=>null,'source_cogs'=>null,
            'source_amount'=>null,'loss_amount'=>null,
            'cogs_ledger_id'=>null,'policy'=>null,
        ];
        if($isScrap){
            $cogs=DB::table('production_invoice_cogs_postings')
                ->where('sales_invoice_line_id',$sale->id)->first();
            if(!$cogs || (int)$cogs->quantity_packages!==(int)$sale->quantity_packages)
                $this->fail('Independent QC scrap source requires the genuine original invoice COGS to be posted first; uncapitalized batches need their own atomic batch-correction workflow.');
            $cost=app(FinishedGoodsCostPostingService::class)
                ->assertCapitalized((int)$qc->production_batch_id,
                    (string)$invoice->invoice_date);
            if((int)$cogs->production_batch_cost_posting_id!==(int)$cost->id)
                $this->fail('Scrapped credit COGS does not come from the verified original batch manufacturing cost.');
            $policy=app(ManufacturingLedgerPolicyService::class)
                ->approved((int)$cost->currency_id);
            if((int)$policy->id!==(int)$cost->manufacturing_cost_ledger_policy_id)
                $this->fail('The original source manufacturing GL policy changed after the signed production capitalization.');
            $this->journal((int)$cogs->journal_entry_id,(int)$cost->currency_id,
                (string)$invoice->invoice_date);
            if(!$this->net((int)$cogs->journal_entry_id,(int)$policy->cogs_ledger_id)
                    ->isEqualTo((string)$cogs->amount) ||
                !$this->net((int)$cogs->journal_entry_id,(int)$policy->finished_inventory_ledger_id)
                    ->isEqualTo(BigDecimal::of((string)$cogs->amount)->negated()))
                $this->fail('The original COGS must be an unreversed factory-currency debit and actual finished-stock inventory credit.');

            $priorQty=0;$priorCost=BigDecimal::zero();$snapshots=[];
            $previousScrap=DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('production_invoice_cogs_posting_id',$cogs->id)
                ->where('treatment','scrap_cogs_reclass')->where('status','posted')
                ->orderBy('id')->get();
            foreach($previousScrap as $other){
                $journal=$this->journal((int)$other->journal_entry_id,
                    (int)$cost->currency_id,(string)$other->posting_date);
                if($other->posting_date>$date ||
                    (int)$other->sales_return_quarantine_id===(int)$qc->id ||
                    !$this->net((int)$journal->id,(int)$policy->cogs_ledger_id)
                        ->isEqualTo(
                            BigDecimal::of((string)$other->source_loss_amount)->negated()
                        ) ||
                    !$this->net((int)$journal->id,(int)$other->loss_ledger_id)
                        ->isEqualTo((string)$other->source_loss_amount))
                    $this->fail('Previous separately approved scrapped-return loss reclassifications are reversed, duplicated or out of original chronological order.');
                $priorQty+=(int)$other->quantity_packages;
                $priorCost=$priorCost->plus((string)$other->source_loss_amount);
                $snapshots[]=[
                    $other->id,$other->sales_return_quarantine_id,
                    $other->journal_entry_id,$other->quantity_packages,
                    $other->source_loss_amount,$other->posting_date,
                ];
            }
            $previousRelease=DB::table('production_return_cost_postings')
                ->where('production_invoice_cogs_posting_id',$cogs->id)
                ->orderBy('id')->get();
            foreach($previousRelease as $other){
                $qcRelease=DB::table('sales_return_quarantines')
                    ->where('id',$other->sales_return_quarantine_id)->first();
                $journal=DB::table('journal_entries')
                    ->where('id',$other->journal_entry_id)->first();
                if(!$qcRelease || !$journal ||
                    $qcRelease->status!=='released' ||
                    $journal->status!=='posted' ||
                    $journal->reversal_of_id ||
                    (int)$journal->currency_id!==(int)$cost->currency_id ||
                    (string)$journal->entry_date!==(string)$qcRelease->decided_on ||
                    $journal->entry_date>$date ||
                    DB::table('journal_entries')
                        ->where('reversal_of_id',$journal->id)->exists() ||
                    !$this->net((int)$journal->id,(int)$policy->finished_inventory_ledger_id)
                        ->isEqualTo((string)$other->amount) ||
                    !$this->net((int)$journal->id,(int)$policy->cogs_ledger_id)
                        ->isEqualTo(
                            BigDecimal::of((string)$other->amount)->negated()
                        ))
                    $this->fail('Earlier released customer returns must be genuine, signed, correctly costed and chronologically prior to this historic scrap loss.');
                $priorQty+=(int)$other->quantity_packages;
                $priorCost=$priorCost->plus((string)$other->amount);
                $snapshots[]=[
                    'released',$other->id,$other->sales_return_quarantine_id,
                    $other->journal_entry_id,$other->quantity_packages,$other->amount,
                ];
            }
            $qty=(int)$qc->quantity_packages;
            $total=BigDecimal::of((string)$cogs->amount);
            if($priorQty<0 || $priorQty+$qty>(int)$cogs->quantity_packages ||
                $priorCost->isNegative() || $priorCost->isGreaterThanOrEqualTo($total))
                $this->fail('Previously credited/restored packages or reclassified scrap loss exceed the source customer sale quantity and original COGS.');
            $amount=$priorQty+$qty===(int)$cogs->quantity_packages
                ?$total->minus($priorCost)
                :$total->multipliedBy((string)$qty)
                    ->dividedBy((string)$cogs->quantity_packages,4,RoundingMode::HalfUp);
            if(!$amount->isPositive() || $amount->isGreaterThan($total->minus($priorCost)))
                $this->fail('Approved original scrapped packages exceed the remaining source factory COGS value.');
            $returnData['currency_id']=(int)$cost->currency_id;
            $returnData['source_cogs_id']=(int)$cogs->id;
            $returnData['source_cogs']=$cogs;
            $returnData['source_amount']=(string)$total->toScale(4);
            $returnData['loss_amount']=(string)$amount->toScale(4,RoundingMode::HalfUp);
            $returnData['cogs_ledger_id']=(int)$policy->cogs_ledger_id;
            $returnData['policy']=$policy;
            $returnData['prior_source_snapshots']=$snapshots;
            app(FinancePeriodClosingService::class)->assertOpenDate($date);
        }
        $returnData['fingerprint']=hash('sha256',json_encode([
            'source_fingerprint'=>$case->source_fingerprint,
            'credit'=>$credit->id,'invoice'=>$sale->id,
            'qc_status'=>$qc->status,'qc_id'=>$qc->id,
            'source_date'=>$date,'treatment'=>$returnData['treatment'],
            'currency_id'=>$returnData['currency_id'],
            'cogs_id'=>$returnData['source_cogs_id'],
            'cogs_amount'=>$returnData['source_amount'],
            'verified_amount'=>$returnData['loss_amount'],
            'cogs_ledger_id'=>$returnData['cogs_ledger_id'],
            'prior'=>$returnData['prior_source_snapshots']??[],
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return $returnData;
    }

    private function unchanged(object $plan,array $s): void
    {
        if((int)$plan->sales_return_quarantine_id!==(int)$s['qc']->id ||
            (int)$plan->sales_credit_note_line_id!==(int)$s['credit']->id ||
            (int)$plan->sales_invoice_line_id!==(int)$s['sale']->id ||
            $plan->treatment!==$s['treatment'] ||
            (string)$plan->source_date!==(string)$s['date'] ||
            (int)$plan->quantity_packages!==(int)$s['qc']->quantity_packages ||
            (int)($plan->currency_id??0)!==(int)($s['currency_id']??0) ||
            (int)($plan->production_invoice_cogs_posting_id??0)!==
                (int)($s['source_cogs_id']??0) ||
            (int)($plan->cogs_ledger_id??0)!==(int)($s['cogs_ledger_id']??0) ||
            ($s['treatment']==='scrap_cogs_reclass' &&
                ((string)$plan->posting_date!==(string)$s['date'] ||
                 !$plan->loss_ledger_id ||
                 !BigDecimal::of((string)$plan->original_invoice_cogs)
                    ->isEqualTo($s['source_amount']) ||
                 !BigDecimal::of((string)$plan->source_loss_amount)
                    ->isEqualTo($s['loss_amount']))) ||
            ($s['treatment']==='quarantine_hold' &&
                ($plan->posting_date!==null || $plan->loss_ledger_id!==null ||
                 $plan->journal_entry_id!==null ||
                 $plan->source_loss_amount!==null)) ||
            !hash_equals((string)$plan->source_fingerprint,(string)$s['fingerprint']))
            $this->fail('Original QC, customer credit, signed source COGS, independent expense account, loss amount or original date changed after the signed proposal.');
        if($s['treatment']==='scrap_cogs_reclass')
            $this->lossLedger((int)$plan->loss_ledger_id,$s['policy']);
    }

    public function submit(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $s=$this->source($caseId);
            $this->independent($s,$actorId);
            $ledger=$s['treatment']==='scrap_cogs_reclass'
                ?(int)($data['loss_ledger_id']??0):null;
            if($ledger!==null)
                $this->lossLedger($ledger,$s['policy']);
            elseif(!empty($data['loss_ledger_id']))
                $this->fail('A quarantined return is a DOCUMENTARY HOLD, not saleable inventory or an approved loss. Do not assign an expense or post a journal.');
            if(DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('finance_historical_correction_case_id',$caseId)->exists())
                $this->fail('The independently reviewed case already has a signed disposition-cost plan.');
            if(DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('sales_return_quarantine_id',$s['qc']->id)
                ->where('treatment',$s['treatment'])->exists())
                $this->fail('This QC source already has a signed plan for its exact held or scrapped disposition. Amendments require a separate audited correction.');
            $id=DB::table('finance_historical_customer_return_disposition_reviews')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'sales_return_quarantine_id'=>$s['qc']->id,
                'sales_credit_note_line_id'=>$s['credit']->id,
                'sales_invoice_line_id'=>$s['sale']->id,
                'production_invoice_cogs_posting_id'=>$s['source_cogs_id'],
                'currency_id'=>$s['currency_id'],
                'cogs_ledger_id'=>$s['cogs_ledger_id'],
                'loss_ledger_id'=>$ledger,
                'treatment'=>$s['treatment'],'status'=>'submitted',
                'source_date'=>$s['date'],
                'posting_date'=>$s['treatment']==='scrap_cogs_reclass'?$s['date']:null,
                'quantity_packages'=>$s['qc']->quantity_packages,
                'original_invoice_cogs'=>$s['source_amount'],
                'source_loss_amount'=>$s['loss_amount'],
                'source_fingerprint'=>$s['fingerprint'],
                'evidence_reference'=>trim($data['evidence_reference']),
                'reconciliation_notes'=>trim($data['reconciliation_notes']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_customer_qc_disposition.submitted',
                'finance_historical_customer_return_disposition_review',$id,
                'Independent historical customer credit and QC disposition submitted. A quarantined hold creates no asset or GL cost.');
            return DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('id',$id)->first();
        });
    }

    private function finishCase(object $case,object $plan,string $notes,int $actorId): void
    {
        DB::table('finance_historical_correction_cases')->where('id',$case->id)->update([
            'status'=>'resolved','open_key'=>null,
            'source_currency_id'=>$plan->currency_id,
            'resolution_reference'=>$plan->approval_reference,
            'resolution_notes'=>$notes,
            'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('finance_historical_correction_events')->insert([
            'finance_historical_correction_case_id'=>$case->id,
            'action'=>'resolved',
            'source_reference'=>$case->source_reference,
            'source_fingerprint'=>$case->source_fingerprint,
            'approval_reference'=>$plan->approval_reference,
            'notes'=>$notes,
            'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function approve(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $plan=DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='submitted')
                $this->fail('Only a submitted, unposted original customer disposition plan can be independently reviewed.');
            $s=$this->source($caseId);
            $this->independent($s,$actorId,$plan);
            $this->unchanged($plan,$s);
            $approved=$data['decision']==='approve';
            $hold=$approved && $s['treatment']==='quarantine_hold';
            DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('id',$plan->id)->update([
                    'status'=>$approved?($hold?'held':'approved'):'rejected',
                    'approval_reference'=>trim($data['approval_reference']),
                    'approval_notes'=>trim($data['approval_notes']),
                    'approved_by'=>$actorId,'approved_at'=>now(),
                    'executed_by'=>$hold?$actorId:null,
                    'executed_at'=>$hold?now():null,
                    'updated_at'=>now(),
                ]);
            if($hold){
                $plan=DB::table('finance_historical_customer_return_disposition_reviews')
                    ->where('id',$plan->id)->first();
                $this->finishCase($s['case'],$plan,
                    'Source-matched original customer credit and QC quarantine reviewed. PHYSICAL QUARANTINE HOLD ONLY: no saleable stock, inventory asset, COGS reversal or loss GL was created. An independent new QC disposition requires a new source-fingerprinted case.',
                    $actorId);
            }
            ActivityLog::record('finance.historical_customer_qc_disposition.'.
                ($approved?($hold?'held':'approved'):'rejected'),
                'finance_historical_customer_return_disposition_review',$plan->id,
                $hold?'Verified customer credit and quarantined QC return held without posting any accounting journal.':'Original customer scrap loss finance decision recorded; no GL posting until separate execution.');
            return DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('id',$plan->id)->first();
        });
    }

    public function post(int $caseId,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$actorId){
            $plan=DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='approved' ||
                $plan->treatment!=='scrap_cogs_reclass' ||
                $plan->journal_entry_id!==null)
                $this->fail('Only a separately approved, unreclassified and independently QC-scrapped original customer return may post the source loss journal.');
            $s=$this->source($caseId);
            $this->independent($s,$actorId,$plan);
            $this->unchanged($plan,$s);
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate((string)$plan->posting_date);
            $entry=DB::table('production_invoice_cogs_postings')
                ->where('id',$plan->production_invoice_cogs_posting_id)->first();
            if(!$entry || (int)$entry->sales_invoice_line_id!==(int)$s['sale']->id ||
                !BigDecimal::of((string)$entry->amount)
                    ->isEqualTo((string)$plan->original_invoice_cogs))
                $this->fail('Original source sale COGS was modified since the independently signed loss authorization.');
            DocumentSequence::firstOrCreate([
                'document_type'=>'historical_scrapped_customer_return',
            ],[
                'prefix'=>'HSL-','next_number'=>1,'padding'=>7,'is_active'=>true,
            ]);
            $journal=JournalEntry::create([
                'number'=>DocumentSequence::issue('historical_scrapped_customer_return'),
                'entry_date'=>$plan->posting_date,
                'reference'=>'QC-SCRAP-'.$s['qc']->id,
                'description'=>'Original customer return scrapped by QC; reclassify existing factory COGS, without restoring finished inventory',
                'status'=>'draft','currency_id'=>$plan->currency_id,
                'created_by'=>$actorId,
            ]);
            $journal->lines()->createMany([
                [
                    'ledger_account_id'=>$plan->loss_ledger_id,
                    'debit'=>$plan->source_loss_amount,'credit'=>'0',
                    'memo'=>'Independent QC scrap reference '.$s['qc']->quality_reference,
                ],
                [
                    'ledger_account_id'=>$plan->cogs_ledger_id,
                    'debit'=>'0','credit'=>$plan->source_loss_amount,
                    'memo'=>'Reclassify previously recorded original sale COGS for credited/scrapped physical packages',
                ],
            ]);
            app(JournalPostingService::class)->post($journal,$actorId);
            if($journal->fresh()->status!=='posted' ||
                !$this->net((int)$journal->id,(int)$plan->loss_ledger_id)
                    ->isEqualTo((string)$plan->source_loss_amount) ||
                !$this->net((int)$journal->id,(int)$plan->cogs_ledger_id)
                    ->isEqualTo(
                        BigDecimal::of((string)$plan->source_loss_amount)->negated()
                    ))
                $this->fail('The source-linked historical scrapped customer return must debit exactly the approved non-COGS loss expense and credit exactly the original customer COGS. No inventory asset entries are allowed.');
            DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('id',$plan->id)->update([
                    'status'=>'posted','journal_entry_id'=>$journal->id,
                    'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
                ]);
            $this->finishCase($s['case'],$plan,
                'Signed original QC scrap and real customer invoice/credit documents reconciled; moved '.
                $plan->source_loss_amount.' original factory currency #'.$plan->currency_id.
                ' FROM existing COGS TO authorized damaged-returns loss expense. '.
                'No inventory, sales revenue, customer receivable, refund, warehouse move, or customer credit was created. '.
                $plan->reconciliation_notes,
                $actorId);
            ActivityLog::record('finance.historical_customer_qc_scrap_loss.posted',
                'finance_historical_customer_return_disposition_review',$plan->id,
                'Original source-currency QC scrapped-return COGS reclassified as independently approved loss; no inventory asset movement.',
                ['original_cogs'=>$plan->production_invoice_cogs_posting_id,
                    'journal_id'=>$journal->id,'amount'=>$plan->source_loss_amount,
                    'currency_id'=>$plan->currency_id]);
            return DB::table('finance_historical_customer_return_disposition_reviews')
                ->where('id',$plan->id)->first();
        });
    }
}
