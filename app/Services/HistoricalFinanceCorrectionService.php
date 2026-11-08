<?php

namespace App\Services;

use App\Models\ActivityLog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review and audit historic source discrepancies without inventing missing
 * inventory value or rewriting old documents. Automatic correction is
 * intentionally limited to COGS for a posted invoice already backed by
 * prior approved finished-goods capitalization and exact dispatch evidence.
 */
class HistoricalFinanceCorrectionService
{
    public const TYPES=[
        'invoice_missing_cogs'=>'Posted invoice missing COGS',
        'batch_uncapitalized'=>'Released batch without verified capitalization',
        'standalone_dispatch'=>'Historic warehouse dispatch without invoice',
        'opening_fifo_unvalued'=>'Unvalued FIFO stock lot requiring original-cost reconciliation',
        'landed_cost_unreconciled'=>'Historic GRN landed cost without GL evidence',
        'supplier_return_unreconciled'=>'Historic supplier return / short credit discrepancy',
    ];

    private function fail(string $message):never
    {
        throw ValidationException::withMessages(['historical_correction'=>$message]);
    }

    /** Snapshot only immutable identifiers and original source attributes. */
    public function source(string $type,int $id):array
    {
        if(!isset(self::TYPES[$type]) || $id<1)$this->fail('Choose a supported historical source document.');
        $table=match($type){
            'invoice_missing_cogs'=>'sales_invoices',
            'batch_uncapitalized'=>'production_batches',
            'standalone_dispatch'=>'finished_good_dispatches',
            'opening_fifo_unvalued'=>'inventory_cost_layers',
            'landed_cost_unreconciled'=>'goods_receipt_landed_costs',
            'supplier_return_unreconciled'=>'purchase_return_records',
        };
        $original=DB::table($table)->where('id',$id)->first();
        if(!$original)$this->fail('The selected original source document no longer exists.');
        $reference='';$date='';$currencyId=null;$immutable=[];
        switch($type){
            case 'invoice_missing_cogs':
                $reference=(string)$original->number;
                $date=(string)$original->invoice_date;
                $currencyId=$original->currency_id;
                $immutable=[
                    'customer_id'=>$original->customer_id,
                    'status'=>$original->status,
                    'currency_id'=>$original->currency_id,
                    'subtotal'=>$original->subtotal,
                    'total'=>$original->total,
                    'revenue_journal_id'=>$original->journal_entry_id,
                    'trade_document_id'=>$original->trade_document_id,
                    'date'=>$date,
                    'lines'=>DB::table('sales_invoice_lines')
                        ->where('sales_invoice_id',$id)->orderBy('id')
                        ->get(['id','finished_good_id','production_batch_id',
                            'dispatch_id','quantity_packages','unit_price'])->toArray(),
                ];
                break;
            case 'batch_uncapitalized':
                $reference=(string)$original->batch_number;
                $date=substr((string)($original->released_at??$original->created_at),0,10);
                $immutable=['production_order_id'=>$original->production_order_id,
                    'finished_good_id'=>$original->finished_good_id,
                    'accepted_packages'=>$original->accepted_packages,
                    'status'=>$original->status,'date'=>$date,
                    'costs'=>DB::table('production_batch_costs')
                        ->where('production_batch_id',$id)->orderBy('id')
                        ->get(['id','currency_id','material_cost','unvalued_quantity'])->toArray()];
                break;
            case 'standalone_dispatch':
                $reference=(string)$original->number;
                $date=(string)$original->dispatch_date;
                $immutable=['date'=>$date,
                    'lines'=>DB::table('finished_good_dispatch_lines')
                        ->where('finished_good_dispatch_id',$id)->orderBy('id')
                        ->get(['id','production_batch_id','finished_good_id',
                            'company_warehouse_id','quantity_packages'])->toArray()];
                break;
            case 'opening_fifo_unvalued':
                $reference='FIFO-LOT-'.$id;
                $date=(string)$original->received_on;
                $currencyId=$original->currency_id;
                $immutable=['source_type'=>$original->source_type,
                    'source_id'=>$original->source_id,
                    'material_id'=>$original->inventory_material_id,
                    'original_quantity'=>$original->original_quantity,
                    'remaining_quantity'=>$original->remaining_quantity,
                    'original_cost'=>$original->original_cost,
                    'currency_id'=>$original->currency_id,'date'=>$date];
                break;
            case 'landed_cost_unreconciled':
                $reference='GRN-LANDED-'.$id;
                $receipt=DB::table('goods_receipts')
                    ->where('id',$original->goods_receipt_id)->first();
                if(!$receipt)$this->fail('Original GRN does not exist.');
                $date=(string)$receipt->received_on;
                $currencyId=$receipt->cost_currency_id;
                $immutable=['goods_receipt_id'=>$original->goods_receipt_id,
                    'component'=>$original->component,'currency_code'=>$original->currency_code,
                    'amount'=>$original->amount,'date'=>$date];
                break;
            case 'supplier_return_unreconciled':
                $reference=(string)$original->number;
                $date=(string)$original->returned_on;
                $currencyId=$original->currency_id;
                $immutable=['goods_receipt_id'=>$original->goods_receipt_id,
                    'currency_id'=>$currencyId,'date'=>$date,
                    'lines'=>DB::table('purchase_return_record_lines')
                        ->where('purchase_return_record_id',$id)->orderBy('id')
                        ->get(['id','goods_receipt_line_id','quantity',
                            'supplier_credit_value_snapshot'])->toArray()];
                break;
        }
        if(!$date || strlen($date)<10)$this->fail('Original source date is unavailable. Restore the document evidence before correction.');
        return [
            'type'=>$type,'id'=>$id,'reference'=>$reference,
            'date'=>substr($date,0,10),'currency_id'=>$currencyId,
            'fingerprint'=>hash('sha256',json_encode(
                $immutable,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION
            )),
        ];
    }

    private function log(int $caseId,string $action,array $source,
        string $notes,int $actorId,?string $reference=null):void
    {
        DB::table('finance_historical_correction_events')->insert([
            'finance_historical_correction_case_id'=>$caseId,
            'action'=>$action,'source_reference'=>$source['reference'],
            'source_fingerprint'=>$source['fingerprint'],
            'approval_reference'=>$reference,'notes'=>$notes,
            'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
        ]);
        ActivityLog::record('finance.historical_correction.'.$action,
            'finance_historical_correction_case',$caseId,
            'Independent source-document correction case '.$action.'.',
            ['source_type'=>$source['type'],'source_id'=>$source['id'],
                'source_reference'=>$source['reference']]);
    }

    public function submit(array $data,int $actorId):object
    {
        return DB::transaction(function()use($data,$actorId){
            $source=$this->source($data['case_type'],(int)$data['source_id']);
            $key=$source['type'].':'.$source['id'];
            if(DB::table('finance_historical_correction_cases')
                ->where('open_key',$key)->exists()){
                $this->fail('An unresolved correction case already exists for this source. Add evidence through the existing case.');
            }
            if($source['type']==='invoice_missing_cogs'){
                $invoice=DB::table('sales_invoices')->where('id',$source['id'])->first();
                if($invoice->status!=='posted')
                    $this->fail('Only a posted invoice can enter historical COGS reconciliation.');
                $missing=DB::table('sales_invoice_lines as line')
                    ->leftJoin('production_invoice_cogs_postings as cost',
                        'cost.sales_invoice_line_id','=','line.id')
                    ->where('line.sales_invoice_id',$source['id'])
                    ->whereNull('cost.id')->exists();
                if(!$missing)$this->fail('All invoice lines already have COGS source postings. Review posted journal validity in the GL reconciliation report instead.');
            }
            $id=DB::table('finance_historical_correction_cases')->insertGetId([
                'case_number'=>'HFC-'.\Illuminate\Support\Str::upper(
                    \Illuminate\Support\Str::random(12)),
                'case_type'=>$source['type'],'source_id'=>$source['id'],
                'source_reference'=>$source['reference'],
                'source_date'=>$source['date'],
                'source_currency_id'=>$source['currency_id'],
                'source_fingerprint'=>$source['fingerprint'],
                'open_key'=>$key,'status'=>'submitted',
                'identified_discrepancy'=>trim($data['identified_discrepancy']),
                'source_evidence'=>trim($data['source_evidence']),
                'requested_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log($id,'submitted',$source,
                trim($data['identified_discrepancy']).' | '.trim($data['source_evidence']),
                $actorId);
            return DB::table('finance_historical_correction_cases')->where('id',$id)->first();
        });
    }

    public function review(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->status!=='submitted')$this->fail('Only submitted historical correction cases can be reviewed.');
            if((int)$case->requested_by===$actorId)
                $this->fail('The requester cannot independently approve or reject the financial correction.');
            $source=$this->source($case->case_type,(int)$case->source_id);
            $approved=$data['decision']==='approve';
            if($approved && $source['fingerprint']!==$case->source_fingerprint)
                $this->fail('Original source document changed after this request. Reject the stale case and submit a fresh source-fingerprinted correction.');
            DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->update([
                    'status'=>$approved?'approved':'rejected',
                    'open_key'=>$approved?$case->open_key:null,
                    'approval_reference'=>trim($data['approval_reference']),
                    'review_notes'=>trim($data['review_notes']),
                    'reviewed_by'=>$actorId,'reviewed_at'=>now(),'updated_at'=>now(),
                ]);
            $this->log($caseId,$approved?'approved':'rejected',$source,
                trim($data['review_notes']),$actorId,$data['approval_reference']);
            return DB::table('finance_historical_correction_cases')->where('id',$caseId)->first();
        });
    }

    /**
     * Withdrawal is audit-only; it cannot reverse or bypass any journal.
     * Requester may withdraw an unreviewed case. An approved case can be
     * invalidated only by its independent reviewer.
     */
    public function withdraw(int $caseId,string $notes,int $actorId):void
    {
        DB::transaction(function()use($caseId,$notes,$actorId){
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->status==='submitted'){
                if((int)$case->requested_by!==$actorId)
                    $this->fail('Only the original requester may withdraw an unreviewed case.');
            }elseif($case->status==='approved'){
                if((int)$case->reviewed_by!==$actorId)
                    $this->fail('Only the independent reviewer who approved this case can invalidate it before execution.');
            }else{
                $this->fail('Only submitted or approved, not-yet-executed cases may be withdrawn.');
            }
            DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->update([
                    'status'=>'withdrawn','open_key'=>null,'updated_at'=>now(),
                ]);
            $source=[
                'type'=>$case->case_type,'id'=>$case->source_id,
                'reference'=>$case->source_reference,
                'fingerprint'=>$case->source_fingerprint,
            ];
            $this->log($caseId,'withdrawn',$source,trim($notes),$actorId);
        });
    }

    public function executeMissingCogs(int $caseId,array $data,int $actorId):int
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            app(FinancePeriodClosingService::class)->holdPostingGate();
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->case_type!=='invoice_missing_cogs' || $case->status!=='approved')
                $this->fail('Only an independently approved, unchanged missing-COGS invoice case can post an accounting correction.');
            if((int)$case->requested_by===$actorId)
                $this->fail('The original correction requester cannot execute the independently approved COGS journal.');
            $source=$this->source($case->case_type,(int)$case->source_id);
            if($source['fingerprint']!==$case->source_fingerprint)
                $this->fail('Original invoice source attributes changed since independent approval. Posting was blocked.');
            app(FinancePeriodClosingService::class)->assertOpenDate($source['date']);
            $invoice=DB::table('sales_invoices')
                ->where('id',$case->source_id)->first();
            $revenue=DB::table('journal_entries')
                ->where('id',$invoice->journal_entry_id??0)->first();
            if(!$invoice || $invoice->status!=='posted' || !$revenue ||
                $revenue->status!=='posted' || $revenue->reversal_of_id ||
                (int)$revenue->currency_id!==(int)$invoice->currency_id ||
                DB::table('journal_entries')
                    ->where('reversal_of_id',$revenue->id)->exists()){
                $this->fail('The original sales revenue journal is absent, reversed, unposted or in a different currency. Reconcile the sales invoice before creating related historic COGS.');
            }
            $receivable=DB::table('trade_documents')
                ->where('id',$invoice->trade_document_id??0)->first();
            if(!$receivable || $receivable->type!=='receivable' ||
                (int)$receivable->trade_party_id!==(int)$invoice->customer_id ||
                (int)$receivable->currency_id!==(int)$invoice->currency_id ||
                !BigDecimal::of((string)$receivable->amount)
                    ->isEqualTo((string)$invoice->total) ||
                (string)$revenue->entry_date!==$source['date']){
                $this->fail('Original sales receivable and revenue journal do not agree with the posted invoice date, customer, currency and source total.');
            }
            $journalDebit=BigDecimal::zero();$journalCredit=BigDecimal::zero();
            foreach(DB::table('journal_lines')
                ->where('journal_entry_id',$revenue->id)->get(['debit','credit']) as $amount){
                $journalDebit=$journalDebit->plus((string)$amount->debit);
                $journalCredit=$journalCredit->plus((string)$amount->credit);
            }
            if(!$journalDebit->isEqualTo((string)$invoice->total) ||
                !$journalCredit->isEqualTo((string)$invoice->total)){
                $this->fail('Original revenue GL journal debits and credits do not both reconcile to the source customer invoice total.');
            }
            // Re-use the existing hard-validated original-cost pathway. It
            // checks batch capitalization date, exact warehouse dispatch,
            // original currency, lot cost and pre-existing COGS sequencing.
            $posted=app(FinishedGoodsCostPostingService::class)
                ->reconcilePostedInvoice((int)$case->source_id,
                    $case->approval_reference.' / '.trim($data['resolution_reference']),
                    $actorId);
            $lines=DB::table('sales_invoice_lines')
                ->where('sales_invoice_id',$case->source_id)->get();
            if($lines->isEmpty())$this->fail('Original invoice lacks source lines.');
            foreach($lines as $line){
                $entry=DB::table('production_invoice_cogs_postings as cost')
                    ->join('production_batch_cost_postings as cost_batch',
                        'cost_batch.id','=','cost.production_batch_cost_posting_id')
                    ->join('manufacturing_cost_ledger_policies as policy',
                        'policy.id','=','cost_batch.manufacturing_cost_ledger_policy_id')
                    ->join('journal_entries as journal',
                        'journal.id','=','cost.journal_entry_id')
                    ->where('cost.sales_invoice_line_id',$line->id)
                    ->select('cost.production_batch_cost_posting_id','cost.quantity_packages',
                        'cost.amount','cost_batch.production_batch_id',
                        'cost_batch.currency_id as cost_currency_id',
                        'policy.cogs_ledger_id','policy.finished_inventory_ledger_id',
                        'journal.id as journal_id','journal.status',
                        'journal.entry_date','journal.currency_id',
                        'journal.reversal_of_id')->first();
                if(!$entry || $entry->status!=='posted' || $entry->reversal_of_id ||
                    (int)$entry->production_batch_id!==(int)$line->production_batch_id ||
                    (int)$entry->quantity_packages!==(int)$line->quantity_packages ||
                    (int)$entry->currency_id!==(int)$entry->cost_currency_id ||
                    (string)$entry->entry_date!==$source['date'] ||
                    DB::table('journal_entries')
                        ->where('reversal_of_id',$entry->journal_id)->exists()){
                    $this->fail('The invoice does not have a valid original-batch, original-currency, same-date COGS journal for every sale line.');
                }
                $cogsDebit=BigDecimal::zero();
                $assetCredit=BigDecimal::zero();
                foreach(DB::table('journal_lines')
                    ->where('journal_entry_id',$entry->journal_id)
                    ->get(['ledger_account_id','debit','credit']) as $glLine){
                    if((int)$glLine->ledger_account_id===(int)$entry->cogs_ledger_id){
                        $cogsDebit=$cogsDebit->plus((string)$glLine->debit)
                            ->minus((string)$glLine->credit);
                    }
                    if((int)$glLine->ledger_account_id===(int)$entry->finished_inventory_ledger_id){
                        $assetCredit=$assetCredit->plus((string)$glLine->credit)
                            ->minus((string)$glLine->debit);
                    }
                }
                if(!$cogsDebit->isEqualTo((string)$entry->amount) ||
                    !$assetCredit->isEqualTo((string)$entry->amount)){
                    $this->fail('Posted source-currency COGS expense and finished-stock asset journal lines do not match the immutable invoice cost.');
                }
            }
            DB::table('finance_historical_correction_cases')->where('id',$caseId)->update([
                'status'=>'resolved','open_key'=>null,
                'resolution_reference'=>trim($data['resolution_reference']),
                'resolution_notes'=>trim($data['resolution_notes']),
                'posted_cogs_lines'=>$posted,'executed_by'=>$actorId,
                'executed_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log($caseId,'resolved',$source,
                'Verified all original invoice cost journals. Newly posted lines: '.$posted.
                '. Evidence: '.trim($data['resolution_notes']),
                $actorId,$data['resolution_reference']);
            return $posted;
        });
    }

    /**
     * Record the independently evidenced purpose of a legacy physical
     * dispatch. This does NOT post COGS, create revenue, restock a lot or
     * mark the historical finance case resolved.
     */
    public function classifyStandaloneDispatch(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->case_type!=='standalone_dispatch' || $case->status!=='approved'){
                $this->fail('Only independently approved warehouse-only dispatch investigations may have a financial-purpose classification.');
            }
            if((int)$case->requested_by===$actorId){
                $this->fail('The person who requested the correction cannot classify their own historical dispatch.');
            }
            $source=$this->source('standalone_dispatch',(int)$case->source_id);
            if(!hash_equals($source['fingerprint'],$case->source_fingerprint)){
                $this->fail('Historical dispatch source changed after finance review. Withdraw the case and verify the original movement.');
            }
            $unlinked=DB::table('finished_good_dispatch_lines as detail')
                ->leftJoin('sales_invoice_lines as line',
                    'line.dispatch_id','=','detail.finished_good_dispatch_id')
                ->where('detail.finished_good_dispatch_id',$case->source_id)
                ->whereNotNull('line.id')->exists();
            if($unlinked){
                $this->fail('This dispatch has acquired a sales-invoice link. Reconcile the actual invoice and original COGS journal instead.');
            }
            if(DB::table('finance_historical_dispatch_dispositions')
                ->where('finished_good_dispatch_id',$case->source_id)->exists()){
                $this->fail('This historical dispatch already has an independently signed classification. A new case and formal correction are needed to change the approved evidence.');
            }
            $purposes=['customer_sale','promotional_sample','production_loss',
                'internal_consumption','unresolved'];
            if(!in_array($data['business_purpose'],$purposes,true)){
                $this->fail('Choose a documented dispatch purpose.');
            }
            $id=DB::table('finance_historical_dispatch_dispositions')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'finished_good_dispatch_id'=>$case->source_id,
                'business_purpose'=>$data['business_purpose'],
                'supporting_document_reference'=>trim($data['supporting_document_reference']),
                'source_evidence'=>trim($data['source_evidence']),
                'remaining_financial_action'=>trim($data['remaining_financial_action']),
                'reviewed_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log($caseId,'disposition_recorded',$source,
                'Physical warehouse-only dispatch reviewed as '.$data['business_purpose'].
                '. Further original-currency financial correction remains outstanding. '.
                trim($data['remaining_financial_action']),
                $actorId,$data['supporting_document_reference']);
            return DB::table('finance_historical_dispatch_dispositions')
                ->where('id',$id)->first();
        });
    }

    /**
     * Capture source-priced, original-currency supplier short-credit
     * evidence. It never posts a supplier payable, receivable, expense,
     * or physical inventory entry. Those require independently approved,
     * purpose-specific accounting documents.
     */
    public function classifySupplierCreditDispute(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->case_type!=='supplier_return_unreconciled' ||
                $case->status!=='approved'){
                $this->fail('Only an independently approved historical supplier-return credit dispute case can receive a short-credit review.');
            }
            if((int)$case->requested_by===$actorId){
                $this->fail('The employee who submitted the original correction case cannot approve its supplier-credit dispute evidence.');
            }
            $source=$this->source($case->case_type,(int)$case->source_id);
            if(!hash_equals($source['fingerprint'],$case->source_fingerprint)){
                $this->fail('Original supplier return source changed since case approval. Withdraw the stale case before recording a dispute.');
            }
            $return=DB::table('purchase_return_records')
                ->where('id',$case->source_id)->lockForUpdate()->first();
            if(!$return || $return->status!=='posted_pending_credit' ||
                $return->financial_status!=='supplier_credit_pending' ||
                DB::table('supplier_debit_notes')
                    ->where('purchase_return_record_id',$case->source_id)->exists()){
                $this->fail('Only a physically posted original-currency supplier return without a credited payable can be investigated for a short-credit dispute.');
            }
            $lines=DB::table('purchase_return_record_lines')
                ->where('purchase_return_record_id',$return->id)->get();
            if($lines->isEmpty()){
                $this->fail('Source return has no original GRN-priced physical material lines.');
            }
            $expected=BigDecimal::zero();
            foreach($lines as $line){
                if($line->supplier_credit_value_snapshot===null ||
                    !BigDecimal::of((string)$line->quantity)->isPositive()){
                    $this->fail('Original source quantity or unit-price snapshot is absent. The supplier difference cannot be valued reliably.');
                }
                $expected=$expected->plus((string)$line->supplier_credit_value_snapshot);
            }
            $expected=$expected->toScale(4,\Brick\Math\RoundingMode::HalfUp);
            $offered=BigDecimal::of((string)$data['supplier_offered_credit'])
                ->toScale(4,\Brick\Math\RoundingMode::Unnecessary);
            if(!$expected->isPositive() || $expected->isGreaterThan('9999999999999999.9999') ||
                $offered->isNegative() || $offered->isGreaterThanOrEqualTo($expected)){
                $this->fail('Document a genuine short-credit offer in the original supplier return currency. Full credits use the normal supplier debit-note workflow.');
            }
            $difference=$expected->minus($offered);
            $permitted=['supplier_claim_pending','potential_nonrecoverable_loss','unresolved'];
            if(!in_array($data['requested_treatment'],$permitted,true)){
                $this->fail('Choose a supported supplier short-credit investigation outcome.');
            }
            if(DB::table('finance_supplier_return_credit_disputes')
                ->where('purchase_return_record_id',$return->id)->exists()){
                $this->fail('This physical supplier return already has immutable signed dispute evidence. Do not record overlapping claimed credits.');
            }
            $id=DB::table('finance_supplier_return_credit_disputes')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'purchase_return_record_id'=>$return->id,
                'currency_id'=>$return->currency_id,
                'source_base_return_value'=>(string)$expected,
                'supplier_offered_credit'=>(string)$offered,
                'unresolved_difference'=>(string)$difference,
                'requested_treatment'=>$data['requested_treatment'],
                'supplier_document_reference'=>trim($data['supplier_document_reference']),
                'source_evidence'=>trim($data['source_evidence']),
                'remaining_approval_action'=>trim($data['remaining_approval_action']),
                'reviewed_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log($caseId,'short_credit_reviewed',$source,
                'Original physical return base value '.$expected.
                '; supplier offered '.$offered.'; disputed difference '.$difference.
                ' in original currency #'.$return->currency_id.
                '. No payable, receivable or loss journal posted. '.
                trim($data['remaining_approval_action']),
                $actorId,$data['supplier_document_reference']);
            return DB::table('finance_supplier_return_credit_disputes')
                ->where('id',$id)->first();
        });
    }

    public function activity(int $caseId):\Illuminate\Support\Collection
    {
        return DB::table('finance_historical_correction_events')
            ->where('finance_historical_correction_case_id',$caseId)
            ->orderBy('id')->get();
    }
}
