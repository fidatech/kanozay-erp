<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductionBatch;
use App\Models\SalesInvoiceLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restricted historical manufacturing cost recovery:
 * - an already dispatched, uncapitalized QC batch;
 * - every dispatch has a real, posted, original-date customer invoice;
 * - no customer returns, unclassified dispatch or unsupported source costs;
 * - all original journal dates are OPEN;
 * - source-backed WIP/finished inventory + all invoice COGS post atomically.
 *
 * It does not create an invoice, alter stock, fabricate costs, or backdate
 * journals into an independently closed accounting month.
 */
final class HistoricalBatchCapitalizationService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_batch'=>$message]);
    }

    private function originalInvoice(object $invoice, string $physicalDate): void
    {
        if($invoice->invoice_status!=='posted' ||
            $invoice->invoice_date!==$physicalDate ||
            !$invoice->invoice_journal_id || !$invoice->trade_document_id)
            $this->fail('Each old physical dispatch must be linked to an actually posted customer invoice on the same original dispatch date.');

        $revenue=DB::table('journal_entries')->where('id',$invoice->invoice_journal_id)->first();
        $receivable=DB::table('trade_documents')->where('id',$invoice->trade_document_id)->first();
        if(!$revenue || $revenue->status!=='posted' || $revenue->reversal_of_id ||
            (string)$revenue->entry_date!==$physicalDate ||
            (int)$revenue->currency_id!==(int)$invoice->invoice_currency_id ||
            DB::table('journal_entries')->where('reversal_of_id',$revenue->id)->exists() ||
            !$receivable || $receivable->type!=='receivable' ||
            (int)$receivable->trade_party_id!==(int)$invoice->customer_id ||
            (int)$receivable->currency_id!==(int)$invoice->invoice_currency_id ||
            (int)$receivable->journal_entry_id!==(int)$revenue->id ||
            !BigDecimal::of((string)$receivable->amount)
                ->isEqualTo((string)$invoice->invoice_total))
            $this->fail('The original customer receivable and sales revenue GL must be valid, posted, unreversed and match the original buyer, invoice and currency.');

        $debit=BigDecimal::zero();$credit=BigDecimal::zero();
        foreach(DB::table('journal_lines')->where('journal_entry_id',$revenue->id)
            ->get(['debit','credit']) as $line){
            $debit=$debit->plus((string)$line->debit);
            $credit=$credit->plus((string)$line->credit);
        }
        if(!$debit->isEqualTo((string)$invoice->invoice_total) ||
            !$credit->isEqualTo((string)$invoice->invoice_total))
            $this->fail('Original customer revenue journal debit and credit totals must both equal the exact posted invoice total.');
    }

    /** Re-read both physical and immutable financial sources for each decision. */
    private function source(int $caseId, string $date): array
    {
        $case=DB::table('finance_historical_correction_cases')->where('id',$caseId)
            ->lockForUpdate()->first();
        abort_unless($case,404);
        if($case->case_type!=='batch_uncapitalized' || $case->status!=='approved' ||
            (int)$case->requested_by===(int)$case->reviewed_by)
            $this->fail('An independently approved and still-open uncapitalized-batch source case is required.');
        $source=app(HistoricalFinanceCorrectionService::class)
            ->source('batch_uncapitalized',(int)$case->source_id);
        if(!hash_equals((string)$case->source_fingerprint,(string)$source['fingerprint']))
            $this->fail('Original batch, accepted package count or frozen costs changed after historical finance review.');

        $batch=ProductionBatch::with(['costs','finishedGood'])
            ->whereKey($case->source_id)->lockForUpdate()->firstOrFail();
        if($batch->status!=='released' || (int)$batch->accepted_packages<1 ||
            DB::table('production_batch_cost_postings')
                ->where('production_batch_id',$batch->id)->exists())
            $this->fail('The QC batch is not released or has already been financially capitalized.');

        $readiness=app(ProductionCostReadinessService::class)
            ->rows(collect([$batch]))->where('batch_id',$batch->id);
        if($readiness->count()!==1 || !$readiness->first()['ready'] ||
            $readiness->first()['gl_posted'])
            $this->fail('Original batch materials, waste, overhead or currency are not ready for source-costed GL capitalization.');
        $preview=$readiness->first();
        $material=BigDecimal::of((string)$preview['material_cost']);
        $overhead=BigDecimal::of((string)$preview['approved_overhead']);
        $abnormal=BigDecimal::of((string)$preview['abnormal_waste_source_cost']);
        $amount=$material->plus($overhead)->minus($abnormal)
            ->toScale(4,RoundingMode::HalfUp);
        if(!$amount->isPositive() || $amount->isGreaterThan('9999999999999999.9999'))
            $this->fail('Frozen original-currency production cost preview is outside the permitted positive GL amount.');
        $single=$batch->costs->first();
        if($batch->costs->count()!==1 || !$single->currency_id ||
            $single->currency_code!==$preview['currency'])
            $this->fail('The frozen batch must have exactly one verified original source currency.');
        $currencyId=(int)$single->currency_id;
        $policy=app(ManufacturingLedgerPolicyService::class)->approved($currencyId);

        $receipt=DB::table('finished_good_movements')
            ->where('production_batch_id',$batch->id)->where('type','production_receipt')
            ->orderBy('id')->get();
        if($receipt->count()!==1 ||
            (int)$receipt->first()->quantity_packages!==(int)$batch->accepted_packages ||
            (string)$receipt->first()->occurred_on>$date)
            $this->fail('The selected manufacturing date must be after the one recorded QC-approved receipt with matching original quantity.');

        $lines=DB::table('finished_good_dispatch_lines as physical')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','physical.finished_good_dispatch_id')
            ->leftJoin('sales_invoice_lines as sold',
                'sold.dispatch_id','=','dispatch.id')
            ->leftJoin('sales_invoices as invoice',
                'invoice.id','=','sold.sales_invoice_id')
            ->where('physical.production_batch_id',$batch->id)
            ->orderBy('dispatch.dispatch_date')->orderBy('physical.id')
            ->select([
                'physical.id as physical_line_id',
                'physical.finished_good_dispatch_id as dispatch_id',
                'physical.finished_good_id','physical.production_batch_id',
                'physical.company_warehouse_id','physical.quantity_packages',
                'dispatch.dispatch_date','dispatch.number as dispatch_number',
                'sold.id as invoice_line_id','sold.sales_invoice_id','sold.dispatch_id as linked_dispatch_id',
                'sold.finished_good_id as invoiced_good_id',
                'sold.production_batch_id as invoiced_batch_id',
                'sold.quantity_packages as invoiced_packages',
                'sold.unit_price','sold.line_total',
                'invoice.status as invoice_status','invoice.invoice_date',
                'invoice.number as invoice_number',
                'invoice.currency_id as invoice_currency_id',
                'invoice.total as invoice_total','invoice.customer_id',
                'invoice.journal_entry_id as invoice_journal_id',
                'invoice.trade_document_id',
            ])->get();

        if($lines->isEmpty() || $lines->count()>100)
            $this->fail('This recovery path requires 1 to 100 fully invoiced, physically dispatched batch lines.');
        $count=0;$dispatchIds=[];$invoiceLines=[];
        foreach($lines as $row){
            if(!$row->invoice_line_id || !$row->sales_invoice_id ||
                $row->dispatch_date<$date ||
                (int)$row->finished_good_id!==(int)$batch->finished_good_id ||
                (int)$row->invoiced_good_id!==(int)$row->finished_good_id ||
                (int)$row->invoiced_batch_id!==(int)$batch->id ||
                (int)$row->linked_dispatch_id!==(int)$row->dispatch_id ||
                (int)$row->invoiced_packages!==(int)$row->quantity_packages ||
                (int)$row->quantity_packages<1)
                $this->fail('The original dispatched batch, invoice line, package quantity and date must reconcile exactly. Undocumented standalone dispatches are excluded.');
            if(isset($dispatchIds[$row->dispatch_id]) ||
                DB::table('finished_good_dispatch_lines')
                    ->where('finished_good_dispatch_id',$row->dispatch_id)->count()!==1)
                $this->fail('Each original invoice must link to a single exact physical dispatch line without ambiguous shared-batch dispatches.');
            $dispatchIds[$row->dispatch_id]=true;
            $this->originalInvoice($row,(string)$row->dispatch_date);
            if(DB::table('production_invoice_cogs_postings')
                ->where('sales_invoice_line_id',$row->invoice_line_id)->exists() ||
                DB::table('finance_historical_dispatch_cost_lines')
                ->where('finished_good_dispatch_line_id',$row->physical_line_id)->exists())
                $this->fail('The old dispatch already has COGS or non-sale GL cost recognition. Duplicate costing is prohibited.');
            if(DB::table('sales_credit_note_lines')
                ->where('sales_invoice_line_id',$row->invoice_line_id)->exists() ||
                DB::table('sales_return_quarantines')
                ->where('production_batch_id',$batch->id)->exists())
                $this->fail('A customer credit or returned/quarantined product needs separate source-level COGS/return accounting and is excluded from this backfill.');
            $count+=(int)$row->quantity_packages;
            $invoiceLines[]=(int)$row->invoice_line_id;
        }
        if($count>(int)$batch->accepted_packages)
            $this->fail('Original physical dispatch count exceeds the QC-accepted production batch packages.');
        if(DB::table('sales_invoice_lines')->where('production_batch_id',$batch->id)
            ->whereNotIn('id',$invoiceLines)->exists())
            $this->fail('Additional invoice lines without a corresponding source dispatch must be reconciled before historical capitalization.');

        $period=app(FinancePeriodClosingService::class);
        $period->assertOpenDate($date);
        foreach($lines->pluck('dispatch_date')->unique() as $dispatchDate)
            $period->assertOpenDate((string)$dispatchDate);
        if($date>now()->toDateString() ||
            $lines->contains(fn($row)=>$row->dispatch_date>now()->toDateString()))
            $this->fail('Historical manufacturing and customer COGS dates cannot be in the future.');

        $overheads=DB::table('production_overhead_allocations')
            ->where('production_batch_id',$batch->id)->orderBy('id')
            ->get(['id','currency_id','amount','status','source_journal_line_id',
                'reviewed_by','reviewed_at'])->toArray();
        $waste=DB::table('production_material_wastes as w')
            ->join('production_order_materials as material',
                'material.id','=','w.production_order_material_id')
            ->leftJoin('production_waste_financial_reviews as review',
                'review.production_material_waste_id','=','w.id')
            ->leftJoin('production_abnormal_waste_cost_reviews as abnormal',
                'abnormal.production_material_waste_id','=','w.id')
            ->where('material.production_order_id',$batch->production_order_id)
            ->orderBy('w.id')
            ->get(['w.id','review.treatment','abnormal.currency_id',
                'abnormal.source_cost_amount','abnormal.expense_ledger_id'])->toArray();

        $signature=hash('sha256',json_encode([
            'case'=>$case->source_fingerprint,
            'batch'=>$batch->id,'date'=>$date,'currency'=>$currencyId,
            'expected_amount'=>(string)$amount,
            'batch_costs'=>$batch->costs->map(fn($c)=>[
                $c->id,$c->currency_id,$c->material_cost,$c->unvalued_quantity,
            ])->all(),
            'policy'=>[$policy->id,$policy->material_inventory_ledger_id,
                $policy->work_in_progress_ledger_id,
                $policy->finished_inventory_ledger_id,$policy->cogs_ledger_id],
            'receipt'=>[$receipt->first()->id,$receipt->first()->occurred_on,
                $receipt->first()->quantity_packages],
            'lines'=>$lines->toArray(),'overheads'=>$overheads,'waste'=>$waste,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'case'=>$case,'batch'=>$batch,
            'lines'=>$lines,'date'=>$date,'currency_id'=>$currencyId,
            'amount'=>(string)$amount,'packages'=>$count,
            'signature'=>$signature,
        ];
    }

    private function matches(object $record, array $s): void
    {
        if((int)$record->production_batch_id!==(int)$s['batch']->id ||
            (int)$record->currency_id!==$s['currency_id'] ||
            (string)$record->accounting_date!==$s['date'] ||
            (int)$record->accepted_packages!==(int)$s['batch']->accepted_packages ||
            (int)$record->dispatched_packages!==$s['packages'] ||
            (int)$record->invoice_line_count!==$s['lines']->count() ||
            !BigDecimal::of((string)$record->expected_original_cost)->isEqualTo($s['amount']) ||
            !hash_equals((string)$record->source_fingerprint,(string)$s['signature']))
            $this->fail('The reviewed historical batch value, source invoices, dispatches or GL evidence have changed since the original proposal.');
    }

    public function submit(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $s=$this->source($caseId,$data['accounting_date']);
            if(in_array($actorId,[(int)$s['case']->requested_by,
                (int)$s['case']->reviewed_by],true))
                $this->fail('A different finance proposer is required after the independent historical case review.');
            if(DB::table('finance_historical_batch_capitalizations')
                ->where('production_batch_id',$s['batch']->id)->exists())
                $this->fail('An original financial capitalization proposal already exists for this batch.');
            $id=DB::table('finance_historical_batch_capitalizations')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'production_batch_id'=>$s['batch']->id,
                'currency_id'=>$s['currency_id'],
                'status'=>'submitted','accounting_date'=>$s['date'],
                'expected_original_cost'=>$s['amount'],
                'accepted_packages'=>$s['batch']->accepted_packages,
                'dispatched_packages'=>$s['packages'],
                'invoice_line_count'=>$s['lines']->count(),
                'source_fingerprint'=>$s['signature'],
                'source_authorization_reference'=>trim($data['source_authorization_reference']),
                'source_reconciliation_notes'=>trim($data['source_reconciliation_notes']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_batch_capitalization.submitted',
                'finance_historical_batch_capitalization',$id,
                'Source-fingerprinted original manufacturing and invoice COGS recovery awaiting independent approval; no GL postings.');
            return DB::table('finance_historical_batch_capitalizations')->where('id',$id)->first();
        });
    }

    public function approve(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $record=DB::table('finance_historical_batch_capitalizations')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($record,404);
            if($record->status!=='submitted')
                $this->fail('Only a submitted, unposted original source-cost recovery can be reviewed.');
            $s=$this->source($caseId,(string)$record->accounting_date);
            if(in_array($actorId,[(int)$record->submitted_by,
                (int)$s['case']->requested_by,(int)$s['case']->reviewed_by],true))
                $this->fail('A separate finance reviewer must approve original manufacturing and invoice cost recovery.');
            $this->matches($record,$s);
            $approved=$data['decision']==='approve';
            DB::table('finance_historical_batch_capitalizations')->where('id',$record->id)->update([
                'status'=>$approved?'approved':'rejected',
                'approval_reference'=>trim($data['approval_reference']),
                'approval_notes'=>trim($data['approval_notes']),
                'approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_batch_capitalization.'.
                ($approved?'approved':'rejected'),
                'finance_historical_batch_capitalization',$record->id,
                'Independent historical manufacturing cost review recorded; no journal posted.');
            return DB::table('finance_historical_batch_capitalizations')->where('id',$record->id)->first();
        });
    }

    public function post(int $caseId,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$actorId){
            $record=DB::table('finance_historical_batch_capitalizations')
                ->where('finance_historical_correction_case_id',$caseId)
                ->lockForUpdate()->first();
            abort_unless($record,404);
            if($record->status!=='approved' || $record->production_batch_cost_posting_id)
                $this->fail('Only an independently approved, not-yet-capitalized batch can be executed.');
            $s=$this->source($caseId,(string)$record->accounting_date);
            if(in_array($actorId,[(int)$record->submitted_by,
                (int)$record->approved_by,
                (int)$s['case']->requested_by,
                (int)$s['case']->reviewed_by],true))
                $this->fail('A different finance executor must post the original source manufacturing and invoice journals.');
            $this->matches($record,$s);

            // Serialize every cost and COGS journal with the financial-period
            // closing gate; any rejection rolls back manufacturing and sales.
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate($s['date']);
            foreach($s['lines']->pluck('dispatch_date')->unique() as $date)
                $period->assertOpenDate((string)$date);

            $cost=app(ManufacturingCostAccountingService::class)->capitalize(
                (int)$s['batch']->id,[
                    'accounting_date'=>$s['date'],
                    'approval_reference'=>$record->approval_reference,
                    'reconciliation_notes'=>$record->source_reconciliation_notes.
                        ' | Historical source authorization: '.$record->source_authorization_reference,
                ],$actorId,$caseId);
            if((int)$cost->currency_id!==$s['currency_id'] ||
                !BigDecimal::of((string)$cost->total_amount)->isEqualTo($s['amount']) ||
                (int)$cost->accepted_packages!==(int)$s['batch']->accepted_packages)
                $this->fail('Actual source-backed manufacturing GL costs differ from independently approved original-currency batch value.');

            $count=0;
            foreach($s['lines'] as $row){
                // Each previously posted customer invoice remains unchanged.
                // Only its missing original-currency COGS asset movement is added.
                app(FinishedGoodsCostPostingService::class)->postInvoiceLine(
                    SalesInvoiceLine::findOrFail($row->invoice_line_id),$actorId);
                $count++;
            }
            DB::table('finance_historical_batch_capitalizations')->where('id',$record->id)->update([
                'status'=>'posted','production_batch_cost_posting_id'=>$cost->id,
                'posted_invoice_cogs_lines'=>$count,
                'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_cases')->where('id',$caseId)->update([
                'status'=>'resolved','open_key'=>null,
                'source_currency_id'=>$s['currency_id'],
                'resolution_reference'=>$record->approval_reference,
                'resolution_notes'=>'Original production capitalization and '.$count.
                    ' source-invoiced COGS lines posted in open original periods. '.
                    $record->source_reconciliation_notes,
                'posted_cogs_lines'=>$count,
                'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_events')->insert([
                'finance_historical_correction_case_id'=>$caseId,
                'action'=>'resolved',
                'source_reference'=>$s['case']->source_reference,
                'source_fingerprint'=>$s['case']->source_fingerprint,
                'approval_reference'=>$record->approval_reference,
                'notes'=>'Original production WIP and finished inventory capitalized at '.
                    $s['amount'].' (currency #'.$s['currency_id'].') on '.$s['date'].
                    '; '.$count.' independently sourced existing invoice COGS lines posted. '.
                    'Original physical movements and sales revenue unchanged. '.
                    $record->source_authorization_reference,
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_batch_capitalization.posted',
                'finance_historical_batch_capitalization',$record->id,
                'Frozen source-cost manufacturing and all previously invoiced COGS posted atomically.',
                ['batch_id'=>$s['batch']->id,'capitalization_id'=>$cost->id,
                    'currency_id'=>$s['currency_id'],'cogs_lines'=>$count]);
            return DB::table('finance_historical_batch_capitalizations')
                ->where('id',$record->id)->first();
        });
    }
}
