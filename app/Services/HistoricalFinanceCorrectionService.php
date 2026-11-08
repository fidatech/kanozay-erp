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
                            'quantity_packages'])->toArray()];
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
            if($source['fingerprint']!==$case->source_fingerprint)
                $this->fail('Original source document changed after this request. Reconcile the source and submit a fresh case.');
            $approved=$data['decision']==='approve';
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

    public function activity(int $caseId):\Illuminate\Support\Collection
    {
        return DB::table('finance_historical_correction_events')
            ->where('finance_historical_correction_case_id',$caseId)
            ->orderBy('id')->get();
    }
}
