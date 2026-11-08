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
 * - every physical dispatch has EITHER a genuine posted original-date
 *   customer invoice OR an independently signed and approved non-sale
 *   purpose (promotional sample, production loss or internal consumption);
 * - each signed non-sale line has separately reviewed original-cost expense
 *   ledger authorization; no returns, unclassified movement or unsupported
 *   source costs;
 * - all original journal dates are OPEN;
 * - source-backed WIP/finished inventory, genuine sales COGS and
 *   signed non-sale expense journals all post atomically.
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
        // Genuine customer returns are inspected after the exact original
        // source sale allocations have been projected, then source-signed.
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
                    !$signed->original_approved_by || !$signed->reviewed_by ||
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

        $saleCosts=[];
        foreach($lines as $row){
            if($row->invoice_line_id)
                $saleCosts[(int)$row->invoice_line_id]=
                    $allocations[(int)$row->physical_line_id];
        }
        $returns=app(HistoricalBatchReturnSourceService::class)
            ->snapshot((int)$batch->id,$lines,$saleCosts,$currencyId,$date);
        $period=app(FinancePeriodClosingService::class);
        $period->assertOpenDate($date);
        foreach($returns as $returned)
            $period->assertOpenDate($returned['date']);
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
        if($returns)$signatureSources['released_credit_returns']=$returns;
        $signature=hash('sha256',json_encode(
            $signatureSources,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'case'=>$case,'batch'=>$batch,
            'lines'=>$lines,'date'=>$date,'currency_id'=>$currencyId,
            'amount'=>(string)$amount,'packages'=>$count,
            'sale_count'=>$saleCount,'non_sale'=>$nonSale,
            'returns'=>$returns,
            'allocations'=>$allocations,'signature'=>$signature,
        ];
    }

    private function independentFromNonSale(array $source,int $actorId): void
    {
        foreach($source['non_sale'] as $review){
            if(in_array($actorId,[
                $review['source_requester'],
                $review['source_reviewer'],
                $review['purpose_reviewer'],
            ],true))
                $this->fail('Separate staff must perform the source-dispatch request, purpose classification, financial proposal, approval and final GL execution.');
        }
    }

    private function independentFromReturns(array $source,int $actorId): void
    {
        foreach($source['returns'] as $return){
            if(in_array($actorId,$return['actors'],true))
                $this->fail('The historical batch finance proposer, approver and final executor must differ from every original customer credit registrar and QC release officer.');
        }
    }

    private function returnRecords(object $plan,array $source): array
    {
        $rows=DB::table('finance_historical_batch_return_allocations')
            ->where('finance_historical_batch_capitalization_id',$plan->id)
            ->orderBy('sales_return_quarantine_id')->get()->keyBy('sales_return_quarantine_id');
        if($plan->return_line_count===null &&
            !$source['returns'] && $rows->isEmpty())
            return []; // Existing reviewed invoiced/mixed plans remain valid.
        if((int)$plan->return_line_count!==count($source['returns']) ||
            $rows->count()!==count($source['returns']))
            $this->fail('The independently approved historical batch has unreviewed or missing QC return allocations.');
        $matched=[];
        foreach($source['returns'] as $returned){
            $entry=$rows->get($returned['qc_id']);
            if(!$entry ||
                (int)$entry->sales_credit_note_line_id!==$returned['credit_line_id'] ||
                (int)$entry->sales_invoice_line_id!==$returned['sales_invoice_line_id'] ||
                (int)$entry->currency_id!==$source['currency_id'] ||
                (string)$entry->original_qc_date!==$returned['date'] ||
                (int)$entry->quantity_packages!==$returned['quantity'] ||
                !BigDecimal::of((string)$entry->original_invoiced_cogs_amount)
                    ->isEqualTo($returned['original_sale_cost']) ||
                !BigDecimal::of((string)$entry->original_return_cost_amount)
                    ->isEqualTo($returned['expected_cost']) ||
                !hash_equals((string)$entry->source_fingerprint,
                    (string)$returned['fingerprint']) ||
                $entry->journal_entry_id!==null ||
                $entry->production_return_cost_posting_id!==null)
                $this->fail('The original QC decision, customer credit source, warehouse restock, original-cost fraction or signed return evidence changed after financial approval.');
            $matched[$returned['qc_id']]=$entry;
        }
        return $matched;
    }

    private function expenseLedger(int $ledgerId,array $source): void
    {
        $policy=app(ManufacturingLedgerPolicyService::class)
            ->approved((int)$source['currency_id']);
        $ledger=\App\Models\LedgerAccount::whereKey($ledgerId)->first();
        if(!$ledger || !$ledger->is_active || $ledger->is_group ||
            $ledger->type!=='expense' ||
            in_array($ledgerId,[
                (int)$policy->cogs_ledger_id,
                (int)$policy->material_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->finished_inventory_ledger_id,
            ],true) ||
            DB::table('entity_ledger_accounts')->where('ledger_account_id',$ledgerId)->exists())
            $this->fail('Use an independently reviewed, active and unmapped sampling, damage or internal-use expense account, separate from sales COGS and manufacturing asset accounts.');
    }

    /** Check every original allocation and its approved purpose account. */
    private function allocationRecords(object $record,array $s): array
    {
        $rows=DB::table('finance_historical_batch_dispatch_allocations')
            ->where('finance_historical_batch_capitalization_id',$record->id)
            ->orderBy('finished_good_dispatch_line_id')->get()
            ->keyBy('finished_good_dispatch_line_id');
        if($record->dispatch_line_count===null && !$s['non_sale'] && $rows->isEmpty())
            return []; // Previously approved all-invoiced legacy proposal.
        if((int)$record->dispatch_line_count!==$s['lines']->count() ||
            (int)$record->non_sale_line_count!==count($s['non_sale']) ||
            $rows->count()!==$s['lines']->count())
            $this->fail('Finance approval allocation does not cover each exact source dispatch and its independently evidenced purpose.');
        $result=[];
        foreach($s['lines'] as $physical){
            $saved=$rows->get($physical->physical_line_id);
            $signed=$s['non_sale'][(int)$physical->physical_line_id]??null;
            $purpose=$signed['purpose']??'customer_sale';
            $expense=$signed?(int)($saved?->expense_ledger_id??0):0;
            if(!$saved ||
                (int)$saved->finished_good_dispatch_line_id!==(int)$physical->physical_line_id ||
                (string)$saved->dispatch_date!==(string)$physical->dispatch_date ||
                (int)$saved->quantity_packages!==(int)$physical->quantity_packages ||
                (int)$saved->currency_id!==(int)$s['currency_id'] ||
                $saved->business_purpose!==$purpose ||
                (int)($saved->sales_invoice_line_id??0)!==(int)($physical->invoice_line_id??0) ||
                (int)($saved->finance_historical_dispatch_disposition_id??0)!==(int)($signed['disposition_id']??0) ||
                ($signed && !$expense) ||
                (!$signed && $saved->expense_ledger_id!==null) ||
                $saved->journal_entry_id!==null ||
                !BigDecimal::of((string)$saved->source_cost_amount)
                    ->isEqualTo($s['allocations'][(int)$physical->physical_line_id]))
                $this->fail('An approved physical dispatch cost, sale link, expense GL, currency or signed purpose changed since financial proposal.');
            if($signed)$this->expenseLedger($expense,$s);
            $result[(int)$physical->physical_line_id]=$saved;
        }
        return $result;
    }

    private function matches(object $record, array $s): void
    {
        if((int)$record->production_batch_id!==(int)$s['batch']->id ||
            (int)$record->currency_id!==$s['currency_id'] ||
            (string)$record->accounting_date!==$s['date'] ||
            (int)$record->accepted_packages!==(int)$s['batch']->accepted_packages ||
            (int)$record->dispatched_packages!==$s['packages'] ||
            (int)$record->invoice_line_count!==$s['sale_count'] ||
            !BigDecimal::of((string)$record->expected_original_cost)->isEqualTo($s['amount']) ||
            !hash_equals((string)$record->source_fingerprint,(string)$s['signature']))
            $this->fail('The reviewed historical batch value, source invoices, dispatches or GL evidence have changed since the original proposal.');
        $this->allocationRecords($record,$s);
        $this->returnRecords($record,$s);
    }

    public function submit(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $s=$this->source($caseId,$data['accounting_date']);
            if(in_array($actorId,[(int)$s['case']->requested_by,
                (int)$s['case']->reviewed_by],true))
                $this->fail('A different finance proposer is required after the independent historical case review.');
            $this->independentFromNonSale($s,$actorId);
            $this->independentFromReturns($s,$actorId);
            $accounts=$data['expense_ledgers']??[];
            $expected=collect(array_keys($s['non_sale']))
                ->map(fn($id)=>(string)$id)->sort()->values()->all();
            $submitted=collect(array_keys($accounts))
                ->map(fn($id)=>(string)(int)$id)->sort()->values()->all();
            if($expected!==$submitted)
                $this->fail('Assign a purpose-appropriate expense account to EVERY signed non-sale source line and never assign one to an invoiced sale.');
            foreach($accounts as $id=>$ledgerId)
                $this->expenseLedger((int)$ledgerId,$s);
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
                'invoice_line_count'=>$s['sale_count'],
                'dispatch_line_count'=>$s['lines']->count(),
                'non_sale_line_count'=>count($s['non_sale']),
                'return_line_count'=>count($s['returns']),
                'source_fingerprint'=>$s['signature'],
                'source_authorization_reference'=>trim($data['source_authorization_reference']),
                'source_reconciliation_notes'=>trim($data['source_reconciliation_notes']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($s['lines'] as $physical){
                $physicalId=(int)$physical->physical_line_id;
                $signed=$s['non_sale'][$physicalId]??null;
                DB::table('finance_historical_batch_dispatch_allocations')->insert([
                    'finance_historical_batch_capitalization_id'=>$id,
                    'finished_good_dispatch_line_id'=>$physicalId,
                    'sales_invoice_line_id'=>$physical->invoice_line_id,
                    'finance_historical_dispatch_disposition_id'=>$signed['disposition_id']??null,
                    'currency_id'=>$s['currency_id'],
                    'expense_ledger_id'=>$signed?(int)$accounts[$physicalId]:null,
                    'business_purpose'=>$signed['purpose']??'customer_sale',
                    'dispatch_date'=>$physical->dispatch_date,
                    'quantity_packages'=>$physical->quantity_packages,
                    'source_cost_amount'=>$s['allocations'][$physicalId],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            foreach($s['returns'] as $returned){
                DB::table('finance_historical_batch_return_allocations')->insert([
                    'finance_historical_batch_capitalization_id'=>$id,
                    'sales_return_quarantine_id'=>$returned['qc_id'],
                    'sales_credit_note_line_id'=>$returned['credit_line_id'],
                    'sales_invoice_line_id'=>$returned['sales_invoice_line_id'],
                    'currency_id'=>$returned['currency_id'],
                    'original_qc_date'=>$returned['date'],
                    'quantity_packages'=>$returned['quantity'],
                    'original_invoiced_cogs_amount'=>$returned['original_sale_cost'],
                    'original_return_cost_amount'=>$returned['expected_cost'],
                    'source_fingerprint'=>$returned['fingerprint'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
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
            $this->independentFromNonSale($s,$actorId);
            $this->independentFromReturns($s,$actorId);
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

    /**
     * Consume one original source-batch inventory line for an independently
     * evidenced NON-SALE historical dispatch. Reuse the existing dispatch
     * expense settlement subledger so sales, reports and GL period checks
     * see the identical original batch cost ledger.
     */
    private function postNonSale(object $sourceLine,object $allocation,
        array $s,object $plan,object $cost,int $actorId): \App\Models\JournalEntry
    {
        $physicalId=(int)$sourceLine->physical_line_id;
        $signed=$s['non_sale'][$physicalId]??null;
        if(!$signed || $allocation->sales_invoice_line_id ||
            (int)$allocation->finance_historical_dispatch_disposition_id!==$signed['disposition_id'])
            $this->fail('An original customer sale cannot be disguised as a non-sale expense.');

        $production=app(FinishedGoodsCostPostingService::class)
            ->assertCapitalized((int)$s['batch']->id,(string)$sourceLine->dispatch_date);
        if((int)$production->id!==(int)$cost->id)
            $this->fail('The manufacturing source cost record differs from the approved historic batch proposal.');

        $policy=app(ManufacturingLedgerPolicyService::class)->approved((int)$cost->currency_id);
        $this->expenseLedger((int)$allocation->expense_ledger_id,$s);
        if(DB::table('sales_invoice_lines')
                ->where('dispatch_id',$sourceLine->dispatch_id)->exists() ||
            DB::table('finance_historical_dispatch_cost_settlements')
                ->where('finished_good_dispatch_id',$sourceLine->dispatch_id)->exists())
            $this->fail('This non-sale source dispatch was already invoiced or separately financially expensed.');

        $priorQty=0;$priorAmount=BigDecimal::zero();
        foreach(DB::table('production_invoice_cogs_postings')
            ->where('production_batch_cost_posting_id',$cost->id)
            ->get(['quantity_packages','amount']) as $row){
            $priorQty+=(int)$row->quantity_packages;
            $priorAmount=$priorAmount->plus((string)$row->amount);
        }
        $existing=DB::table('finance_historical_dispatch_cost_lines as expense')
            ->join('finance_historical_dispatch_cost_settlements as settlement',
                'settlement.id','=','expense.finance_historical_dispatch_cost_settlement_id')
            ->where('expense.production_batch_cost_posting_id',$cost->id)
            ->where('settlement.status','posted')
            ->get(['expense.quantity_packages','expense.source_cost_amount']);
        foreach($existing as $row){
            $priorQty+=(int)$row->quantity_packages;
            $priorAmount=$priorAmount->plus((string)$row->source_cost_amount);
        }
        if(DB::table('production_return_cost_postings as recovered')
            ->join('production_invoice_cogs_postings as sold',
                'sold.id','=','recovered.production_invoice_cogs_posting_id')
            ->where('sold.production_batch_cost_posting_id',$cost->id)->exists())
            $this->fail('The historical batch now has an earlier customer return recovery. This mixed-dispatch path excludes returns.');
        $qty=(int)$sourceLine->quantity_packages;
        $total=BigDecimal::of((string)$cost->total_amount);
        if($priorQty<0 || $priorQty+$qty>(int)$cost->accepted_packages ||
            $priorAmount->isNegative() || $priorAmount->isGreaterThanOrEqualTo($total))
            $this->fail('Historical sold and non-sale dispatched stock already consumes the original batch quantity or cost.');
        $expected=$priorQty+$qty===(int)$cost->accepted_packages
            ?$total->minus($priorAmount)
            :$total->multipliedBy((string)$qty)
                ->dividedBy((string)$cost->accepted_packages,4,RoundingMode::HalfUp);
        if(!$expected->isPositive() ||
            $expected->isGreaterThan($total->minus($priorAmount)) ||
            !$expected->isEqualTo((string)$allocation->source_cost_amount) ||
            !$expected->isEqualTo($s['allocations'][$physicalId]))
            $this->fail('Non-sale original-currency financial cost differs from the independently authorized source allocation.');
        $amount=(string)$expected->toScale(4,RoundingMode::HalfUp);

        $assetBalance=BigDecimal::zero();
        foreach(DB::table('journal_lines as gl')
            ->join('journal_entries as journal','journal.id','=','gl.journal_entry_id')
            ->where('journal.status','posted')
            ->where('journal.currency_id',$cost->currency_id)
            ->whereDate('journal.entry_date','<=',$sourceLine->dispatch_date)
            ->where('gl.ledger_account_id',$policy->finished_inventory_ledger_id)
            ->get(['gl.debit','gl.credit']) as $line){
            $assetBalance=$assetBalance->plus((string)$line->debit)
                ->minus((string)$line->credit);
        }
        if($assetBalance->isLessThan($amount))
            $this->fail('Posted finished inventory ledger lacks sufficient original-currency asset value for the original non-sale dispatch date.');

        \App\Models\DocumentSequence::firstOrCreate([
            'document_type'=>'historical_dispatch_expense',
        ],[
            'prefix'=>'HDE-','next_number'=>1,'padding'=>7,'is_active'=>true,
        ]);
        $journal=\App\Models\JournalEntry::create([
            'number'=>\App\Models\DocumentSequence::issue('historical_dispatch_expense'),
            'entry_date'=>$sourceLine->dispatch_date,
            'reference'=>substr($sourceLine->dispatch_number,0,100),
            'description'=>'Original '.$signed['purpose'].' finished goods issue '.
                $sourceLine->dispatch_number.' / batch '.$s['batch']->batch_number,
            'currency_id'=>$cost->currency_id,'status'=>'draft',
            'created_by'=>$actorId,
        ]);
        $journal->lines()->createMany([
            [
                'ledger_account_id'=>$allocation->expense_ledger_id,
                'debit'=>$amount,'credit'=>'0',
                'memo'=>'Original signed '.$signed['purpose'].' evidence '.$signed['document'],
            ],
            [
                'ledger_account_id'=>$policy->finished_inventory_ledger_id,
                'debit'=>'0','credit'=>$amount,
                'memo'=>'Original posted batch inventory cost / physical dispatch '.$physicalId,
            ],
        ]);
        app(\App\Services\Accounting\JournalPostingService::class)->post($journal,$actorId);

        $settlementId=DB::table('finance_historical_dispatch_cost_settlements')
            ->insertGetId([
                'finance_historical_correction_case_id'=>$signed['case_id'],
                'finance_historical_dispatch_disposition_id'=>$signed['disposition_id'],
                'finished_good_dispatch_id'=>$sourceLine->dispatch_id,
                'business_purpose'=>$signed['purpose'],
                'status'=>'posted','posting_date'=>$sourceLine->dispatch_date,
                'source_authorization_reference'=>$plan->source_authorization_reference,
                'expense_recognition_evidence'=>$signed['evidence'].
                    ' | Batch finance approval: '.$plan->approval_notes,
                'approval_reference'=>$plan->approval_reference,
                'approval_notes'=>$plan->approval_notes,
                'submitted_by'=>$plan->submitted_by,
                'approved_by'=>$plan->approved_by,
                'posted_by'=>$actorId,'approved_at'=>$plan->approved_at,
                'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        DB::table('finance_historical_dispatch_cost_lines')->insert([
            'finance_historical_dispatch_cost_settlement_id'=>$settlementId,
            'finished_good_dispatch_line_id'=>$physicalId,
            'production_batch_cost_posting_id'=>$cost->id,
            'currency_id'=>$cost->currency_id,
            'finished_inventory_ledger_id'=>$policy->finished_inventory_ledger_id,
            'expense_ledger_id'=>$allocation->expense_ledger_id,
            'quantity_packages'=>$qty,
            'original_batch_total'=>$cost->total_amount,
            'source_cost_amount'=>$amount,
            'journal_entry_id'=>$journal->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('finance_historical_correction_cases')
            ->where('id',$signed['case_id'])
            ->where('status','approved')->update([
                'status'=>'resolved','open_key'=>null,
                'source_currency_id'=>$cost->currency_id,
                'resolution_reference'=>$plan->approval_reference,
                'resolution_notes'=>'Original '.$signed['purpose'].
                    ' finished-goods cost '.$amount.' source currency #'.
                    $cost->currency_id.' recognized within approved mixed-batch WIP/COGS recovery. '.
                    $plan->source_reconciliation_notes,
                'executed_by'=>$actorId,'executed_at'=>now(),
                'updated_at'=>now(),
            ]);
        DB::table('finance_historical_correction_events')->insert([
            'finance_historical_correction_case_id'=>$signed['case_id'],
            'action'=>'resolved',
            'source_reference'=>$signed['source_reference'],
            'source_fingerprint'=>$signed['source_fingerprint'],
            'approval_reference'=>$plan->approval_reference,
            'notes'=>'Approved original-currency '.$signed['purpose'].' inventory expense '.
                $amount.' / journal '.$journal->number.' / batch '.
                $s['batch']->batch_number.'; signed disposition '.$signed['document'].
                '. Physical movement and customer sales unchanged.',
            'actor_id'=>$actorId,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        ActivityLog::record('finance.historical_mixed_dispatch.expensed',
            'finance_historical_dispatch_cost_settlement',$settlementId,
            'Previously dispatched finished batch expensed in its approved non-sale purpose, original currency and exact historical quantity.',
            ['batch_id'=>$s['batch']->id,'source_line'=>$physicalId,
                'journal_id'=>$journal->id,'amount'=>$amount]);
        return $journal;
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
            $this->independentFromNonSale($s,$actorId);
            $this->independentFromReturns($s,$actorId);
            $this->matches($record,$s);

            // Serialize every cost and COGS journal with the financial-period
            // closing gate; any rejection rolls back manufacturing and sales.
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate($s['date']);
            foreach($s['lines']->pluck('dispatch_date')->unique() as $date)
                $period->assertOpenDate((string)$date);
            foreach($s['returns'] as $returned)
                $period->assertOpenDate($returned['date']);

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

            $costedSales=0;$costedNonSale=0;$costedReturns=0;
            $approvedLines=$this->allocationRecords($record,$s);
            $approvedReturns=$this->returnRecords($record,$s);
            // Rebuild the immutable original-date accounting sequence:
            // physical sales and independently signed non-sale dispatches
            // before already QC-released customer returns on the same day.
            // This lets original COGS exist before its return recovery, and
            // prevents a later sale from being costed ahead of an earlier QC.
            $events=[];
            foreach($s['lines'] as $physical){
                $events[]=[
                    'type'=>'dispatch','date'=>(string)$physical->dispatch_date,
                    'id'=>(int)$physical->physical_line_id,'source'=>$physical,
                ];
            }
            foreach($s['returns'] as $returned){
                $events[]=[
                    'type'=>'return','date'=>$returned['date'],
                    'id'=>$returned['qc_id'],'source'=>$returned,
                ];
            }
            usort($events,fn($x,$y)=>[
                $x['date'],$x['type']==='dispatch'?0:1,$x['id'],
            ] <=> [
                $y['date'],$y['type']==='dispatch'?0:1,$y['id'],
            ]);
            foreach($events as $event){
                if($event['type']==='return'){
                    $returned=$event['source'];
                    $approved=$approvedReturns[$returned['qc_id']]??null;
                    if(!$approved)
                        $this->fail('A previously QC-released return is missing its original finance-approved, immutable source cost allocation.');
                    $actual=app(FinishedGoodsCostPostingService::class)
                        ->postReturnRecovery(
                            \App\Models\SalesReturnQuarantine::findOrFail($returned['qc_id']),
                            $returned['date'],$actorId,null,(int)$record->id
                        );
                    $sourceCog=DB::table('production_invoice_cogs_postings')
                        ->where('sales_invoice_line_id',$returned['sales_invoice_line_id'])
                        ->first();
                    $sourceJournal=DB::table('journal_entries')
                        ->where('id',$actual->journal_entry_id)->first();
                    if(!$sourceCog || !$sourceJournal ||
                        (int)$sourceCog->production_batch_cost_posting_id!==(int)$cost->id ||
                        (int)$actual->production_invoice_cogs_posting_id!==(int)$sourceCog->id ||
                        (int)$actual->quantity_packages!==$returned['quantity'] ||
                        (int)$actual->sales_return_quarantine_id!==$returned['qc_id'] ||
                        $sourceJournal->status!=='posted' ||
                        (string)$sourceJournal->entry_date!==$returned['date'] ||
                        (int)$sourceJournal->currency_id!==$s['currency_id'] ||
                        !BigDecimal::of((string)$actual->amount)
                            ->isEqualTo((string)$approved->original_return_cost_amount) ||
                        !BigDecimal::of((string)$actual->amount)
                            ->isEqualTo($returned['expected_cost']) ||
                        !BigDecimal::of((string)$sourceCog->amount)
                            ->isEqualTo($returned['original_sale_cost']))
                        $this->fail('Historical QC recovery differs from the signed original customer sale, manufacturing currency, batch cost, recoverable amount or actual posted GL journal.');
                    DB::table('finance_historical_batch_return_allocations')
                        ->where('id',$approved->id)->update([
                            'production_return_cost_posting_id'=>$actual->id,
                            'journal_entry_id'=>$actual->journal_entry_id,
                            'updated_at'=>now(),
                        ]);
                    $costedReturns++;
                    continue;
                }
                $row=$event['source'];
                $physicalId=(int)$row->physical_line_id;
                $signedLine=$approvedLines[$physicalId]??null;
                if($row->invoice_line_id){
                    // Existing posted customer revenue is never recreated.
                    $cogs=app(FinishedGoodsCostPostingService::class)->postInvoiceLine(
                        SalesInvoiceLine::findOrFail($row->invoice_line_id),$actorId);
                    if($signedLine &&
                        ((int)$signedLine->sales_invoice_line_id!==(int)$row->invoice_line_id ||
                         !BigDecimal::of((string)$cogs->amount)
                             ->isEqualTo((string)$signedLine->source_cost_amount)))
                        $this->fail('Historical invoice COGS differs from its independently approved original source cost allocation.');
                    $journalId=(int)$cogs->journal_entry_id;
                    $costedSales++;
                }else{
                    if(!$signedLine)
                        $this->fail('An original non-sale dispatch has no independently authorized expense source line.');
                    $journal=$this->postNonSale($row,$signedLine,$s,$record,$cost,$actorId);
                    $journalId=(int)$journal->id;
                    $costedNonSale++;
                }
                if($signedLine){
                    DB::table('finance_historical_batch_dispatch_allocations')
                        ->where('id',$signedLine->id)->update([
                            'journal_entry_id'=>$journalId,'updated_at'=>now(),
                        ]);
                }
            }
            if($costedSales!==$s['sale_count'] ||
                $costedNonSale!==count($s['non_sale']) ||
                $costedReturns!==count($s['returns']))
                $this->fail('The posting count differs from the independently approved physical dispatch, invoice and QC-return sources.');
            DB::table('finance_historical_batch_capitalizations')->where('id',$record->id)->update([
                'status'=>'posted','production_batch_cost_posting_id'=>$cost->id,
                'posted_invoice_cogs_lines'=>$costedSales,
                'posted_non_sale_lines'=>$costedNonSale,
                'posted_return_lines'=>$costedReturns,
                'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_cases')->where('id',$caseId)->update([
                'status'=>'resolved','open_key'=>null,
                'source_currency_id'=>$s['currency_id'],
                'resolution_reference'=>$record->approval_reference,
                'resolution_notes'=>'Original production capitalization and '.$costedSales.
                    ' invoice COGS lines + '.$costedNonSale.
                    ' independently authorized non-sale dispatch expenses and '.
                    $costedReturns.' previously QC-released customer-return inventory recoveries posted in their open original periods. '.
                    $record->source_reconciliation_notes,
                'posted_cogs_lines'=>$costedSales,
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
                    '; '.$costedSales.' original invoice COGS lines and '.$costedNonSale.
                    ' signed historical non-sale expense lines and '.
                    $costedReturns.' original QC-return asset/COGS restorations posted. '.
                    'Original physical movements and sales revenue unchanged. '.
                    $record->source_authorization_reference,
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_batch_capitalization.posted',
                'finance_historical_batch_capitalization',$record->id,
                'Frozen batch source cost, original invoice COGS, signed non-sale expense and independently QC-released return-cost restorations posted atomically.',
                ['batch_id'=>$s['batch']->id,'capitalization_id'=>$cost->id,
                    'currency_id'=>$s['currency_id'],'cogs_lines'=>$costedSales,
                    'non_sale_lines'=>$costedNonSale,
                    'returned_lines'=>$costedReturns]);
            return DB::table('finance_historical_batch_capitalizations')
                ->where('id',$record->id)->first();
        });
    }
}
