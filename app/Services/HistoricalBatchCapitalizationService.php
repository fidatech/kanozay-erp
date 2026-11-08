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

        $receivableLedger=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'customer','entity_id'=>$invoice->customer_id,
            'currency_id'=>$invoice->invoice_currency_id,'purpose'=>'receivable',
        ])->value('ledger_account_id');
        if(!$receivableLedger)
            $this->fail('Original customer receivable account mapping is missing or currency-mismatched.');
        $debit=BigDecimal::zero();$credit=BigDecimal::zero();
        $receivableDebit=BigDecimal::zero();$incomeCredit=BigDecimal::zero();
        $journalLines=DB::table('journal_lines as row')
            ->join('ledger_accounts as ledger','ledger.id','=','row.ledger_account_id')
            ->where('row.journal_entry_id',$revenue->id)
            ->get(['row.ledger_account_id','row.debit','row.credit','ledger.type']);
        if($journalLines->count()!==2)
            $this->fail('The original posted sales journal must contain exactly one customer receivable debit and one revenue credit.');
        foreach($journalLines as $line){
            $debit=$debit->plus((string)$line->debit);
            $credit=$credit->plus((string)$line->credit);
            if((int)$line->ledger_account_id===(int)$receivableLedger)
                $receivableDebit=$receivableDebit->plus((string)$line->debit)
                    ->minus((string)$line->credit);
            if($line->type==='income')
                $incomeCredit=$incomeCredit->plus((string)$line->credit)
                    ->minus((string)$line->debit);
        }
        if(!$debit->isEqualTo((string)$invoice->invoice_total) ||
            !$credit->isEqualTo((string)$invoice->invoice_total) ||
            !$receivableDebit->isEqualTo((string)$invoice->invoice_total) ||
            !$incomeCredit->isEqualTo((string)$invoice->invoice_total))
            $this->fail('Original customer revenue journal must debit the matched customer receivable and credit the income ledger by the exact invoice total.');
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
            $this->fail('This correction requires 1 to 100 fully source-linked historical physical dispatch lines.');
        if(DB::table('sales_credit_note_lines')->where('production_batch_id',$batch->id)->exists() ||
            DB::table('sales_return_quarantines')->where('production_batch_id',$batch->id)->exists())
            $this->fail('Historic customer credits, returned goods or quarantine changes require a separate inventory and COGS correction before this batch can be reconstructed.');

        $count=0;$saleCount=0;$dispatchIds=[];$invoiceLines=[];$nonSale=[];
        foreach($lines as $row){
            if($row->dispatch_date<$date ||
                (int)$row->finished_good_id!==(int)$batch->finished_good_id ||
                (int)$row->quantity_packages<1)
                $this->fail('Original physical dispatch, batch, accepted product, quantity or date mismatch.');
            if(isset($dispatchIds[$row->dispatch_id]) ||
                DB::table('finished_good_dispatch_lines')
                    ->where('finished_good_dispatch_id',$row->dispatch_id)->count()!==1)
                $this->fail('Historical recovery needs one unambiguous original physical batch line per dispatch document.');
            $dispatchIds[$row->dispatch_id]=true;
            if($row->invoice_line_id){
                if(!$row->sales_invoice_id ||
                    (int)$row->invoiced_good_id!==(int)$row->finished_good_id ||
                    (int)$row->invoiced_batch_id!==(int)$batch->id ||
                    (int)$row->linked_dispatch_id!==(int)$row->dispatch_id ||
                    (int)$row->invoiced_packages!==(int)$row->quantity_packages)
                    $this->fail('Previously invoiced dispatch and original sale line do not match physical item, batch and quantity.');
                $this->originalInvoice($row,(string)$row->dispatch_date);
                if(DB::table('production_invoice_cogs_postings')
                    ->where('sales_invoice_line_id',$row->invoice_line_id)->exists())
                    $this->fail('One source customer invoice was already financially costed. Double COGS recovery is prohibited.');
                $invoiceLines[]=(int)$row->invoice_line_id;
                $saleCount++;
            }else{
                $signed=DB::table('finance_historical_dispatch_dispositions as purpose')
                    ->join('finance_historical_correction_cases as review',
                        'review.id','=','purpose.finance_historical_correction_case_id')
                    ->where('purpose.finished_good_dispatch_id',$row->dispatch_id)
                    ->select('purpose.*','review.status as review_status',
                        'review.case_type as review_type','review.source_id as reviewed_source_id',
                        'review.source_date as reviewed_source_date',
                        'review.source_reference as reviewed_source_reference',
                        'review.source_fingerprint as reviewed_source_fingerprint',
                        'review.requested_by as original_requested_by',
                        'review.reviewed_by as original_approved_by')->first();
                if(!$signed || $signed->review_type!=='standalone_dispatch' ||
                    $signed->review_status!=='approved' ||
                    (int)$signed->reviewed_source_id!==(int)$row->dispatch_id ||
                    (string)$signed->reviewed_source_date!==(string)$row->dispatch_date ||
                    (int)$signed->original_requested_by===(int)$signed->original_approved_by ||
                    (int)$signed->original_requested_by===(int)$signed->reviewed_by ||
                    !in_array($signed->business_purpose,[
                        'promotional_sample','production_loss','internal_consumption',
                    ],true))
                    $this->fail('Every non-sale dispatch needs a separate approved historical case and independently documented sampling, loss or internal-use disposition. Sales and unresolved classification cannot be expensed.');
                $original=app(HistoricalFinanceCorrectionService::class)
                    ->source('standalone_dispatch',(int)$row->dispatch_id);
                if(!hash_equals((string)$signed->reviewed_source_fingerprint,
                        (string)$original['fingerprint']))
                    $this->fail('An original non-sale dispatch changed after its independent purpose review.');
                if(DB::table('finance_historical_dispatch_cost_settlements')
                    ->where('finished_good_dispatch_id',$row->dispatch_id)->exists() ||
                    DB::table('finance_historical_dispatch_cost_lines')
                    ->where('finished_good_dispatch_line_id',$row->physical_line_id)->exists())
                    $this->fail('This non-sale physical dispatch already has an expense proposal or source cost journal. An audited amendment is required.');
                $nonSale[(int)$row->physical_line_id]=[
                    'disposition_id'=>(int)$signed->id,
                    'case_id'=>(int)$signed->finance_historical_correction_case_id,
                    'dispatch_id'=>(int)$row->dispatch_id,
                    'purpose'=>$signed->business_purpose,
                    'document'=>$signed->supporting_document_reference,
                    'evidence'=>$signed->source_evidence,
                    'financial_action'=>$signed->remaining_financial_action,
                    'purpose_reviewer'=>(int)$signed->reviewed_by,
                    'source_requester'=>(int)$signed->original_requested_by,
                    'source_reviewer'=>(int)$signed->original_approved_by,
                    'source_reference'=>$signed->reviewed_source_reference,
                    'source_fingerprint'=>$signed->reviewed_source_fingerprint,
                ];
            }
            $count+=(int)$row->quantity_packages;
        }
        if($count>(int)$batch->accepted_packages)
            $this->fail('All documented historical physical dispatches exceed the accepted batch stock quantity.');
        if(DB::table('sales_invoice_lines')->where('production_batch_id',$batch->id)
            ->whereNotIn('id',$invoiceLines)->exists())
            $this->fail('Additional customer invoice lines exist without a unique matching original physical dispatch.');
        $allocations=[];
        $charged=BigDecimal::zero();$usedQty=0;
        foreach($lines as $row){
            $qty=(int)$row->quantity_packages;
            $usedQty+=$qty;
            $costed=$usedQty===(int)$batch->accepted_packages
                ? $amount->minus($charged)
                : $amount->multipliedBy((string)$qty)
                    ->dividedBy((string)$batch->accepted_packages,4,RoundingMode::HalfUp);
            if(!$costed->isPositive() ||
                $costed->isGreaterThan($amount->minus($charged)))
                $this->fail('Original source cost is insufficient to allocate a positive value to every dispatched batch line.');
            $charged=$charged->plus($costed);
            $allocations[(int)$row->physical_line_id]=(string)$costed->toScale(4);
        }

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

        $signatureSources=[
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
        ];
        // Preserve fingerprints of old fully-invoiced proposals. New
        // mixed-source plans also bind all independently signed dispositions.
        if($nonSale)$signatureSources['signed_non_sale']=$nonSale;
        $signature=hash('sha256',json_encode(
            $signatureSources,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'case'=>$case,'batch'=>$batch,
            'lines'=>$lines,'date'=>$date,'currency_id'=>$currencyId,
            'amount'=>(string)$amount,'packages'=>$count,
            'sale_count'=>$saleCount,'non_sale'=>$nonSale,
            'allocations'=>$allocations,'signature'=>$signature,
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

            // The manufacturing override is permitted ONLY during this
            // same transaction's atomic executor. On ANY failure this
            // transient posting state and all journals roll back together.
            DB::table('finance_historical_batch_capitalizations')
                ->where('id',$record->id)->update([
                    'status'=>'posting','updated_at'=>now(),
                ]);
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
