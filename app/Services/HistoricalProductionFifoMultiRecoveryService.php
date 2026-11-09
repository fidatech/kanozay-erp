<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Strict original-source historical manufacturing reconstruction:
 * 2..12 independently documented opening FIFO lots, ONE genuine QC-released
 * batch, ONE original material-issue date, ONE common ORIGINAL currency.
 * Every physical input must be accounted for. Normal overhead, waste,
 * customer returns, mixed non-sale and other issue dates are blocked.
 *
 * Each original opening asset/equity is posted once, WIP combines only
 * genuinely issued original lot costs, finished stock closes WIP and all
 * original posted customer invoices get source COGS in ONE DB transaction.
 */
final class HistoricalProductionFifoMultiRecoveryService
{
    private function fail(string $message):never
    {
        throw ValidationException::withMessages([
            'historical_multi_fifo'=>$message,
        ]);
    }

    private function monetary(BigDecimal|string $cost):string
    {
        $value=BigDecimal::of((string)$cost)->toScale(4,RoundingMode::Unnecessary);
        if(!$value->isPositive() ||
            $value->isGreaterThan('9999999999999999.9999'))
            $this->fail('Source original-currency cost must be positive and fit a 20,4 journal amount.');
        return (string)$value;
    }

    private function approvalProof(array $reviewIds,int $equityLedgerId,?int $currentCaseId=null):array
    {
        $ids=array_map('intval',$reviewIds);
        $ids=array_values(array_unique($ids));
        sort($ids,SORT_NUMERIC);
        if(count($ids)<2 || count($ids)>12 ||
            in_array(0,$ids,true))
            $this->fail('Coordinated historical source recovery requires between 2 and 12 DISTINCT previously approved opening FIFO production cost reviews.');

        $equity=LedgerAccount::find($equityLedgerId);
        if(!$equity || !$equity->is_active || $equity->is_group ||
            $equity->type!=='equity' ||
            DB::table('entity_ledger_accounts')
                ->where('ledger_account_id',$equityLedgerId)->exists())
            $this->fail('All selected original source opening lots must be supported by an independently evidenced, active, nonmapped original opening-equity ledger.');

        $lots=[];$batchId=null;$currencyId=null;$policy=null;
        $issueDate=null;$openingTotal=BigDecimal::zero();
        $materialTotal=BigDecimal::zero();$remainingTotal=BigDecimal::zero();
        $sourceActors=[];$allConsumptions=[];$allMovements=[];
        $verifiedService=app(HistoricalProductionFifoSourceCostReviewService::class);

        foreach($ids as $reviewId){
            $verified=$verifiedService->verifiedApproved($reviewId);
            $wip=$verified['plan'];
            $state=$verified['state'];
            $proof=$state['proof'];
            if(count($proof['batches'])!==1)
                $this->fail('An original opening FIFO source feeding more than one production batch cannot be partially financially recognized in this single-batch executor.');
            $currentBatch=(int)array_key_first($proof['batches']);
            if($batchId===null)$batchId=$currentBatch;
            if($batchId!==$currentBatch)
                $this->fail('Every independently approved source lot must have gone into the EXACT same physical released production batch.');
            if($currencyId===null){
                $currencyId=(int)$wip->currency_id;
                $policy=$state['policy'];
            }
            if($currencyId!==(int)$wip->currency_id ||
                (int)$state['policy']->id!==(int)$policy->id ||
                (int)$policy->material_inventory_ledger_id!==
                    (int)$wip->source_inventory_ledger_id ||
                (int)$policy->work_in_progress_ledger_id!==
                    (int)$wip->target_wip_ledger_id)
                $this->fail('All original opening-source material costs must be supported by ONE genuine common original currency and unchanged approved manufacturing ledger policy; invented currency conversion is forbidden.');
            $openingDate=$proof['original']['layer']->received_on->toDateString();
            foreach($state['lines'] as $line){
                if((int)$line['production_batch_id']!==$batchId)
                    $this->fail('The signed original FIFO issue references a different production batch.');
                if($issueDate===null)$issueDate=$line['date'];
                if($issueDate!==$line['date'] || $line['date']<$openingDate)
                    $this->fail('This safe coordinated recovery requires one authentic material-issue date shared by all source lots, following every physical original stock opening. Multi-date WIP is a separate plan.');
                $cid=(int)$line['consumption_id'];
                if(isset($allConsumptions[$cid]))
                    $this->fail('The same original FIFO consumption cannot fund two opening lots or twice increase production WIP.');
                $allConsumptions[$cid]=[
                    'lot_id'=>(int)$wip->inventory_cost_layer_id,
                    'source_review_id'=>$reviewId,
                    'movement_id'=>(int)$line['movement_id'],
                    'material_line_id'=>(int)$line['material_line_id'],
                    'date'=>$line['date'],
                    'quantity'=>$line['quantity'],
                    'amount'=>$line['proposed_wip_cost'],
                    'source_fingerprint'=>$line['fingerprint'],
                ];
                $allMovements[(int)$line['movement_id']]=true;
            }

            if(DB::table('finance_historical_fifo_production_recoveries')
                    ->where('inventory_cost_layer_id',$wip->inventory_cost_layer_id)->exists() ||
                DB::table('finance_historical_fifo_multi_recovery_lots')
                    ->where('inventory_cost_layer_id',$wip->inventory_cost_layer_id)
                    ->when($currentCaseId!==null,fn($q)=>
                        $q->where('finance_historical_fifo_multi_recovery_id','!=',
                            $currentCaseId))->exists())
                $this->fail('An original FIFO opening lot already belongs to a signed historical production recovery. Duplicate original opening assets or WIP expenses are prohibited.');
            $openingTotal=$openingTotal->plus($state['original_cost']);
            $materialTotal=$materialTotal->plus($state['consumed_cost']);
            $remainingTotal=$remainingTotal->plus($state['remaining_cost']);
            $sourceActors=array_merge($sourceActors,$verified['actors']);
            $lots[]=[
                'review_id'=>$reviewId,
                'lot_id'=>(int)$wip->inventory_cost_layer_id,
                'opening_date'=>$openingDate,
                'source_amount'=>$state['original_cost'],
                'consumed_amount'=>$state['consumed_cost'],
                'remaining_amount'=>$state['remaining_cost'],
                'cost_source_signature'=>$wip->source_fingerprint,
                'original_physical_signature'=>$proof['physical_fingerprint'],
                'source_currency'=>$state['currency']->code,
                'original_ownership_evidence'=>$wip->original_cost_evidence_reference,
            ];
        }
        foreach([
            $openingTotal,$materialTotal,$remainingTotal,
        ] as $value){
            if($value->isGreaterThan('9999999999999999.9999'))
                $this->fail('Combined multi-lot original cost exceeds the original-currency GL precision. It cannot be truncated or split across unauthorized journals.');
        }
        if(!$openingTotal->isEqualTo($materialTotal->plus($remainingTotal)) ||
            $issueDate===null)
            $this->fail('The original multi-lot opening asset cost must equal all signed production source WIP cost plus genuine remaining raw-material inventory value.');
        if(in_array($equityLedgerId,[
            (int)$policy->material_inventory_ledger_id,
            (int)$policy->work_in_progress_ledger_id,
            (int)$policy->finished_inventory_ledger_id,
            (int)$policy->cogs_ledger_id,
        ],true))
            $this->fail('Original source equity may not be a manufacturing, raw inventory, finished inventory or COGS account.');

        $batch=ProductionBatch::with('checks')->findOrFail($batchId);
        $finishedDate=substr((string)$batch->released_at,0,10);
        $receipts=DB::table('finished_good_movements')
            ->where('production_batch_id',$batchId)
            ->where('type','production_receipt')->get();
        if($batch->status!=='released' || !$batch->released_at ||
            !$finishedDate || strlen($finishedDate)!==10 ||
            (int)$batch->accepted_packages<1 ||
            $receipts->count()!==1 ||
            (int)$receipts->first()->quantity_packages!==(int)$batch->accepted_packages ||
            (string)$receipts->first()->occurred_on>$finishedDate ||
            $issueDate>$finishedDate ||
            $finishedDate>now()->toDateString())
            $this->fail('Source-proven multi-lot production requires one genuine QC-released physical accepted-goods receipt after every original raw material issue.');

        $snapshots=DB::table('production_batch_costs')
            ->where('production_batch_id',$batchId)->get();
        $allQty=BigDecimal::zero();
        foreach($allConsumptions as $c)
            $allQty=$allQty->plus($c['quantity']);
        if($snapshots->count()!==1 ||
            $snapshots->first()->currency_id!==null ||
            ($snapshots->first()->material_cost!==null &&
                !BigDecimal::of((string)$snapshots->first()->material_cost)->isZero()) ||
            !BigDecimal::of((string)$snapshots->first()->unvalued_quantity)
                ->isEqualTo($allQty))
            $this->fail('The genuine original frozen manufacturing-cost snapshot must represent ALL and ONLY these unvalued physical opening FIFO source material quantities. Other valued/raw costs require a different combined source case.');

        $lines=DB::table('production_order_materials')
            ->where('production_order_id',$batch->production_order_id)->get();
        if($lines->isEmpty())$this->fail('No original materials are linked to the actual production order.');
        $outbound=DB::table('inventory_stock_movements')
            ->where('reference_type','production_order_material')
            ->whereIn('reference_id',$lines->pluck('id')->all())
            ->where('direction','out')->get();
        $includedMovementIds=array_keys($allMovements);
        sort($includedMovementIds,SORT_NUMERIC);
        $realMovementIds=$outbound->pluck('id')
            ->map(fn($id)=>(int)$id)->sort()->values()->all();
        if($outbound->isEmpty() ||
            count($allMovements)!==$outbound->count() ||
            $includedMovementIds!==$realMovementIds)
            $this->fail('Original physical production material movements include source lots or material issue dates outside this complete coordinated FIFO recovery.');
        foreach($lines as $materialLine){
            $expected=BigDecimal::of((string)($materialLine->issued_quantity??'0'));
            $movementRows=$outbound->where('reference_id',$materialLine->id);
            if(!$expected->isPositive()){
                if($movementRows->isNotEmpty())
                    $this->fail('Original production material line shows zero issued units but authentic physical outward stock movements exist. Never infer or omit their cost.');
                continue;
            }
            $actual=BigDecimal::zero();
            if($movementRows->isEmpty())
                $this->fail('An original production ingredient or packaging issue has no matching physical stock movement.');
            foreach($movementRows as $move){
                if((string)$move->occurred_on!==$issueDate ||
                    (int)$move->inventory_material_id!==
                        (int)$materialLine->inventory_material_id)
                    $this->fail('Historic production material physical issue date and material identity do not match the signed original FIFO lots.');
                $physicalQty=BigDecimal::zero();
                $allRecorded=DB::table('inventory_cost_consumptions')
                    ->where('inventory_stock_movement_id',$move->id)->get();
                foreach($allRecorded as $consumed){
                    $cid=(int)$consumed->id;
                    if(!isset($allConsumptions[$cid]) ||
                        (int)$allConsumptions[$cid]['movement_id']!==(int)$move->id ||
                        (int)$allConsumptions[$cid]['material_line_id']!==
                            (int)$materialLine->id ||
                        (int)$allConsumptions[$cid]['lot_id']!==
                            (int)$consumed->inventory_cost_layer_id ||
                        !BigDecimal::of((string)$allConsumptions[$cid]['quantity'])
                            ->isEqualTo((string)$consumed->quantity))
                        $this->fail('One real original production material issue consumed an unreviewed or duplicate FIFO source. All ingredients must be financially recognized exactly once.');
                    $physicalQty=$physicalQty->plus((string)$consumed->quantity);
                }
                if(!$physicalQty->isEqualTo((string)$move->quantity))
                    $this->fail('Source FIFO consumption quantities do not equal genuine original outbound production material physical movement.');
                $actual=$actual->plus((string)$move->quantity);
            }
            if(!$actual->isEqualTo($expected))
                $this->fail('Actual physically issued original production material line quantity differs from the complete signed source lot quantity.');
        }

        if(DB::table('production_overhead_allocations')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('production_material_wastes as waste')
                ->join('production_order_materials as item',
                    'item.id','=','waste.production_order_material_id')
                ->where('item.production_order_id',$batch->production_order_id)->exists() ||
            DB::table('production_batch_cost_postings')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_fifo_production_recoveries')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_fifo_multi_recoveries')
                ->where('production_batch_id',$batchId)
                ->when($currentCaseId!==null,fn($q)=>
                    $q->where('id','!=',$currentCaseId))->exists() ||
            DB::table('finance_historical_batch_capitalizations')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_correction_cases')
                ->where('case_type','batch_uncapitalized')
                ->where('source_id',$batchId)
                ->whereIn('status',['submitted','approved'])->exists() ||
            DB::table('production_invoice_cogs_postings as cogs')
                ->join('sales_invoice_lines as sold',
                    'sold.id','=','cogs.sales_invoice_line_id')
                ->where('sold.production_batch_id',$batchId)->exists())
            $this->fail('Original batch has overhead, material waste, prior manufacturing cost or an existing signed source capitalization case. Partial/duplicate WIP and finished inventory recognition is forbidden.');
        if(DB::table('sales_credit_note_lines')->where('production_batch_id',$batchId)->exists() ||
            DB::table('sales_return_quarantines')->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_dispatch_dispositions as purpose')
                ->join('finished_good_dispatch_lines as physical',
                    'physical.finished_good_dispatch_id','=','purpose.finished_good_dispatch_id')
                ->where('physical.production_batch_id',$batchId)->exists())
            $this->fail('Customer credits, QC returns or non-sale physical dispatches require a different complete historic source valuation. This pure sales COGS executor does not guess returns or losses.');

        $dispatches=DB::table('finished_good_dispatch_lines as physical')
            ->join('finished_good_dispatches as head',
                'head.id','=','physical.finished_good_dispatch_id')
            ->where('physical.production_batch_id',$batchId)
            ->orderBy('head.dispatch_date')->orderBy('physical.id')
            ->select('physical.*','head.dispatch_date')->get();
        $saleLines=DB::table('sales_invoice_lines')
            ->where('production_batch_id',$batchId)->get();
        if($dispatches->count()!==$saleLines->count())
            $this->fail('One original batch physical customer dispatch has no genuine matching posted invoice or has an unaccounted non-sale disposition.');
        $sales=[];$saleIds=[];$dispatched=0;
        foreach($dispatches as $physical){
            if((string)$physical->dispatch_date<$finishedDate ||
                (string)$physical->dispatch_date>now()->toDateString() ||
                DB::table('finished_good_dispatch_lines')
                    ->where('finished_good_dispatch_id',
                        $physical->finished_good_dispatch_id)->count()!==1)
                $this->fail('Historical customer dispatch predated QC finished release, is future dated or has ambiguous physical lines.');
            $matched=$saleLines->where('dispatch_id',
                $physical->finished_good_dispatch_id);
            if($matched->count()!==1 ||
                DB::table('sales_invoice_lines')
                    ->where('dispatch_id',
                        $physical->finished_good_dispatch_id)->count()!==1)
                $this->fail('Each genuine original customer sale must have one physical source dispatch and one unique invoice line.');
            $sale=$matched->first();
            $invoice=DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first();
            if(!$invoice)
                $this->fail('The physical original customer sale is missing its authentic invoice.');
            $sales[]=app(HistoricalProductionFifoRecoveryService::class)
                ->verifiedCustomerInvoiceSource($physical,$sale,$invoice);
            $saleIds[]=(int)$sale->id;
            $dispatched+=(int)$physical->quantity_packages;
        }
        if($dispatched>(int)$batch->accepted_packages ||
            $saleLines->pluck('id')->sort()->values()->all()!==
                collect($saleIds)->sort()->values()->all())
            $this->fail('Original accepted goods are insufficient for actual customer physical dispatch and genuine invoice quantities.');

        $source=compact('batch','policy','lots','sales','issueDate','finishedDate');
        $source['review_ids']=$ids;
        $source['currency_id']=$currencyId;
        $source['equity_id']=$equityLedgerId;
        $source['opening_total']=$this->monetary($openingTotal);
        $source['material_total']=$this->monetary($materialTotal);
        $source['remaining_total']=(string)$remainingTotal->toScale(4);
        $source['source_issue_lines']=count($allConsumptions);
        $source['invoice_line_ids']=$saleIds;
        $source['source_actors']=array_values(array_unique(
            array_filter(array_map('intval',$sourceActors))
        ));
        $source['original_issue_source']=$allConsumptions;
        $source['fingerprint']=hash('sha256',json_encode([
            'approved_lots'=>$lots,
            'approved_source_reviews'=>$ids,
            'source_consumptions'=>$allConsumptions,
            'batch_id'=>$batchId,
            'batch_identity'=>[
                $batch->id,$batch->batch_number,$batch->status,
                $batch->accepted_packages,$batch->production_order_id,
                $batch->released_at,$batch->finished_good_id
            ],
            'actual_QC_receipt'=>$receipts->toArray(),
            'QC_checks'=>$batch->checks->toArray(),
            'frozen_cost_snapshot'=>$snapshots->toArray(),
            'physical_material_issues'=>$outbound->toArray(),
            'original_production_materials'=>$lines->toArray(),
            'actual_sales_with_revenue'=>$sales,
            'ledger_policy'=>[
                $policy->id,$policy->currency_id,
                $policy->material_inventory_ledger_id,
                $policy->work_in_progress_ledger_id,
                $policy->finished_inventory_ledger_id,
                $policy->cogs_ledger_id
            ],
            'original_opening_equity'=>$equityLedgerId,
            'currency_id'=>$currencyId,
            'source_material_cost'=>$source['material_total'],
            'opening_total'=>$source['opening_total'],
            'original_issue_date'=>$issueDate,
            'original_finished_date'=>$finishedDate,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return $source;
    }

    private function independent(array $source,int $actorId,?object $case=null):void
    {
        $actors=$source['source_actors'];
        if($case){
            $actors[]=(int)$case->submitted_by;
            $actors[]=(int)($case->approved_by??0);
        }
        if(in_array($actorId,$actors,true))
            $this->fail('Original opening stock registrars, issue authors, source-provenance officers, lot cost reviewers, coordinated recovery proposer, independent reviewer and executor must be separately authorized.');
    }

    private function frozen(object $case,array $source):void
    {
        if((int)$case->production_batch_id!==(int)$source['batch']->id ||
            (int)$case->currency_id!==$source['currency_id'] ||
            (int)$case->opening_equity_ledger_id!==$source['equity_id'] ||
            (string)$case->original_issue_date!==$source['issueDate'] ||
            (string)$case->original_finished_date!==$source['finishedDate'] ||
            !BigDecimal::of((string)$case->original_opening_total)
                ->isEqualTo($source['opening_total']) ||
            !BigDecimal::of((string)$case->source_material_total)
                ->isEqualTo($source['material_total']) ||
            !BigDecimal::of((string)$case->remaining_original_raw_total)
                ->isEqualTo($source['remaining_total']) ||
            (int)$case->source_lot_count!==count($source['lots']) ||
            (int)$case->original_issue_line_count!==$source['source_issue_lines'] ||
            (int)$case->genuine_invoice_line_count!==count($source['invoice_line_ids']) ||
            !hash_equals((string)$case->source_fingerprint,$source['fingerprint']))
            $this->fail('The original signed multi-FIFO source cost, QC receipt, finished batch, raw inputs, customer revenue, original dates, currency or reviewer-approved ledger policy changed.');
        $records=DB::table('finance_historical_fifo_multi_recovery_lots')
            ->where('finance_historical_fifo_multi_recovery_id',$case->id)
            ->get()->keyBy('finance_historical_fifo_production_cost_review_id');
        if($records->count()!==count($source['lots']))
            $this->fail('One independently approved original opening FIFO lot was omitted, replaced or duplicated in the signed recovery.');
        foreach($source['lots'] as $lot){
            $r=$records->get($lot['review_id']);
            if(!$r || (int)$r->inventory_cost_layer_id!==$lot['lot_id'] ||
                (string)$r->original_opening_date!==$lot['opening_date'] ||
                !BigDecimal::of((string)$r->original_source_total)
                    ->isEqualTo($lot['source_amount']) ||
                !BigDecimal::of((string)$r->consumed_wip_cost)
                    ->isEqualTo($lot['consumed_amount']) ||
                !BigDecimal::of((string)$r->remaining_raw_cost)
                    ->isEqualTo($lot['remaining_amount']) ||
                !hash_equals((string)$r->source_fingerprint,
                    $lot['cost_source_signature']) ||
                $r->opening_journal_id!==null)
                $this->fail('A genuine source original opening lot signature or accounting amount is missing or has a prior journal before final execution.');
        }
    }

    public function submit(array $reviewIds,array $data,int $actorId):object
    {
        return DB::transaction(function()use($reviewIds,$data,$actorId){
            $ids=array_values(array_unique(array_map('intval',$reviewIds)));
            sort($ids,SORT_NUMERIC);
            $lotIds=DB::table('finance_historical_fifo_production_cost_reviews')
                ->whereIn('id',$ids)->orderBy('inventory_cost_layer_id')
                ->pluck('inventory_cost_layer_id')->map(fn($id)=>(int)$id)->all();
            if(count($lotIds)!==count($ids))
                $this->fail('All selected source WIP finance review IDs must exist and be distinct.');
            $materials=DB::table('inventory_cost_layers')
                ->whereIn('id',$lotIds)->pluck('inventory_material_id')
                ->unique()->sort()->values()->all();
            DB::table('inventory_materials')->whereIn('id',$materials)
                ->orderBy('id')->lockForUpdate()->get();
            DB::table('inventory_cost_layers')->whereIn('id',$lotIds)
                ->orderBy('id')->lockForUpdate()->get();
            $source=$this->approvalProof($ids,(int)$data['opening_equity_ledger_id']);
            ProductionBatch::whereKey($source['batch']->id)
                ->lockForUpdate()->firstOrFail();
            $this->independent($source,$actorId);
            $caseId=DB::table('finance_historical_fifo_multi_recoveries')
                ->insertGetId([
                    'production_batch_id'=>$source['batch']->id,
                    'currency_id'=>$source['currency_id'],
                    'opening_equity_ledger_id'=>$source['equity_id'],
                    'original_issue_date'=>$source['issueDate'],
                    'original_finished_date'=>$source['finishedDate'],
                    'original_opening_total'=>$source['opening_total'],
                    'source_material_total'=>$source['material_total'],
                    'remaining_original_raw_total'=>$source['remaining_total'],
                    'source_lot_count'=>count($source['lots']),
                    'original_issue_line_count'=>$source['source_issue_lines'],
                    'genuine_invoice_line_count'=>count($source['invoice_line_ids']),
                    'source_fingerprint'=>$source['fingerprint'],
                    'status'=>'submitted',
                    'approval_reference'=>trim($data['approval_reference']),
                    'reconciliation_notes'=>trim($data['reconciliation_notes']),
                    'submitted_by'=>$actorId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            foreach($source['lots'] as $lot)
                DB::table('finance_historical_fifo_multi_recovery_lots')->insert([
                    'finance_historical_fifo_multi_recovery_id'=>$caseId,
                    'finance_historical_fifo_production_cost_review_id'=>$lot['review_id'],
                    'inventory_cost_layer_id'=>$lot['lot_id'],
                    'original_opening_date'=>$lot['opening_date'],
                    'original_source_total'=>$lot['source_amount'],
                    'consumed_wip_cost'=>$lot['consumed_amount'],
                    'remaining_raw_cost'=>$lot['remaining_amount'],
                    'source_fingerprint'=>$lot['cost_source_signature'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_multi_fifo.submitted',
                'finance_historical_fifo_multi_recovery',$caseId,
                'Full source-linked opening lots, original raw/WIP and QC finished batch and actual customer invoices submitted for independent original-date financial approval. No GL or physical stock modified.');
            return DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->first();
        });
    }

    private function sourceForCase(object $case):array
    {
        $ids=DB::table('finance_historical_fifo_multi_recovery_lots')
            ->where('finance_historical_fifo_multi_recovery_id',$case->id)
            ->orderBy('finance_historical_fifo_production_cost_review_id')
            ->pluck('finance_historical_fifo_production_cost_review_id')
            ->map(fn($id)=>(int)$id)->all();
        $source=$this->approvalProof($ids,
            (int)$case->opening_equity_ledger_id,(int)$case->id);
        $this->frozen($case,$source);
        return $source;
    }

    public function approve(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $case=DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->status!=='submitted')
                $this->fail('Only an unchanged, submitted original-source multi-FIFO production plan can receive a separate review.');
            $source=$this->sourceForCase($case);
            $this->independent($source,$actorId,$case);
            DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->update([
                    'status'=>$data['decision']==='approve'?'approved':'rejected',
                    'review_reference'=>trim($data['review_reference']),
                    'review_notes'=>trim($data['review_notes']),
                    'approved_by'=>$actorId,'approved_at'=>now(),
                    'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_multi_fifo.'.
                    ($data['decision']==='approve'?'approved':'rejected'),
                'finance_historical_fifo_multi_recovery',$caseId,
                'Independently signed full multi-source original lot/equity, physical material WIP, QC released finished goods and authentic customer revenue cost validation. No journal yet.');
            return DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->first();
        });
    }

    private function journal(int $currencyId,string $date,int $debit,int $credit,
        string $amount,string $reference,string $memo,int $actorId):JournalEntry
    {
        if($debit===$credit)$this->fail('An original WIP recovery journal cannot debit and credit the same ledger.');
        $this->monetary($amount);
        DocumentSequence::firstOrCreate(
            ['document_type'=>'historical_multi_fifo'],[
                'prefix'=>'HMF-','next_number'=>1,'padding'=>7,'is_active'=>true,
            ]
        );
        $j=JournalEntry::create([
            'number'=>DocumentSequence::issue('historical_multi_fifo'),
            'entry_date'=>$date,
            'reference'=>substr($reference,0,100),
            'description'=>$memo,'status'=>'draft',
            'currency_id'=>$currencyId,'created_by'=>$actorId,
        ]);
        $j->lines()->createMany([
            ['ledger_account_id'=>$debit,'debit'=>$amount,'credit'=>'0',
                'memo'=>substr($memo,0,255)],
            ['ledger_account_id'=>$credit,'debit'=>'0','credit'=>$amount,
                'memo'=>substr($memo,0,255)],
        ]);
        app(JournalPostingService::class)->post($j,$actorId);
        return $j;
    }

    public function post(int $caseId,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$actorId){
            $initial=DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->first();
            abort_unless($initial,404);
            $lotIds=DB::table('finance_historical_fifo_multi_recovery_lots')
                ->where('finance_historical_fifo_multi_recovery_id',$caseId)
                ->pluck('inventory_cost_layer_id')->all();
            $materials=DB::table('inventory_cost_layers')
                ->whereIn('id',$lotIds)->pluck('inventory_material_id')
                ->unique()->sort()->values()->all();
            DB::table('inventory_materials')->whereIn('id',$materials)
                ->orderBy('id')->lockForUpdate()->get();
            DB::table('inventory_cost_layers')->whereIn('id',$lotIds)
                ->orderBy('id')->lockForUpdate()->get();
            ProductionBatch::whereKey($initial->production_batch_id)
                ->lockForUpdate()->firstOrFail();
            $case=DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->lockForUpdate()->first();
            if(!$case || $case->status!=='approved' || !$case->approved_by ||
                $case->wip_journal_id || $case->finished_journal_id ||
                $case->production_batch_cost_posting_id)
                $this->fail('Only an independently approved, completely unposted multi-opening FIFO production source can execute.');
            $source=$this->sourceForCase($case);
            $this->independent($source,$actorId,$case);
            $close=app(FinancePeriodClosingService::class);
            $close->holdPostingGate();
            foreach($source['lots'] as $lot)
                $close->assertOpenDate($lot['opening_date']);
            $close->assertOpenDate($source['issueDate']);
            $close->assertOpenDate($source['finishedDate']);
            foreach($source['sales'] as $invoice)
                $close->assertOpenDate((string)$invoice['invoice'][3]);
            app(FinishedGoodLotControlService::class)
                ->assertSourceLotsClear($source['batch']);
            app(FinishedBatchQualityGate::class)
                ->assertApproved($source['batch']);
            $p=$source['policy'];$currency=$source['currency_id'];
            foreach($source['lots'] as $lot){
                $j=$this->journal($currency,$lot['opening_date'],
                    (int)$p->material_inventory_ledger_id,
                    (int)$source['equity_id'],$lot['source_amount'],
                    'HMF-OPEN-'.$lot['lot_id'],
                    'Actual historical source FIFO opening inventory/equity lot #'.$lot['lot_id'],
                    $actorId);
                DB::table('finance_historical_fifo_multi_recovery_lots')
                    ->where('finance_historical_fifo_multi_recovery_id',$caseId)
                    ->where('inventory_cost_layer_id',$lot['lot_id'])
                    ->update(['opening_journal_id'=>$j->id,'updated_at'=>now()]);
            }
            $issue=$this->journal($currency,$source['issueDate'],
                (int)$p->work_in_progress_ledger_id,
                (int)$p->material_inventory_ledger_id,$source['material_total'],
                'HMF-WIP-'.$source['batch']->id,
                'Actual independently signed multi-FIFO raw material issued to source production WIP',
                $actorId);
            $finished=$this->journal($currency,$source['finishedDate'],
                (int)$p->finished_inventory_ledger_id,
                (int)$p->work_in_progress_ledger_id,$source['material_total'],
                'HMF-FG-'.$source['batch']->id,
                'Actual independently QC-released multi-original-FIFO finished-goods stock',
                $actorId);
            $costId=DB::table('production_batch_cost_postings')->insertGetId([
                'production_batch_id'=>$source['batch']->id,
                'manufacturing_cost_ledger_policy_id'=>$p->id,
                'currency_id'=>$currency,
                'accepted_packages'=>$source['batch']->accepted_packages,
                'material_amount'=>$source['material_total'],
                'abnormal_waste_amount'=>'0.0000',
                'overhead_amount'=>'0.0000',
                'total_amount'=>$source['material_total'],
                'accounting_date'=>$source['finishedDate'],
                'issue_journal_id'=>$issue->id,
                'capitalization_journal_id'=>$finished->id,
                'approval_reference'=>$case->approval_reference,
                'reconciliation_notes'=>$case->reconciliation_notes,
                'approved_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($source['invoice_line_ids'] as $lineId){
                $costed=app(FinishedGoodsCostPostingService::class)
                    ->postInvoiceLine(SalesInvoiceLine::findOrFail($lineId),$actorId);
                if(!$costed ||
                    (int)$costed->sales_invoice_line_id!==(int)$lineId ||
                    (int)$costed->production_batch_cost_posting_id!==$costId)
                    $this->fail('Genuine original dated customer COGS could not be posted within the one all-or-nothing batch inventory cost correction.');
            }
            foreach($source['lots'] as $lot){
                DB::table('inventory_cost_layers')
                    ->where('id',$lot['lot_id'])->update([
                        'original_cost'=>(string)BigDecimal::of($lot['source_amount'])
                            ->toScale(10),
                        'currency_id'=>$currency,
                        'currency_code'=>$lot['source_currency'],
                        'updated_at'=>now(),
                    ]);
                DB::table('finance_historical_fifo_production_cost_lines')
                    ->where('finance_historical_fifo_production_cost_review_id',
                        $lot['review_id'])->update([
                        'historical_wip_journal_id'=>$issue->id,
                        'updated_at'=>now(),
                    ]);
                DB::table('finance_historical_fifo_production_cost_reviews')
                    ->where('id',$lot['review_id'])->update([
                        'status'=>'posted','updated_at'=>now(),
                    ]);
            }
            DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->update([
                    'status'=>'posted',
                    'wip_journal_id'=>$issue->id,
                    'finished_journal_id'=>$finished->id,
                    'production_batch_cost_posting_id'=>$costId,
                    'executed_by'=>$actorId,'executed_at'=>now(),
                    'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_multi_fifo.posted',
                'finance_historical_fifo_multi_recovery',$caseId,
                'Multiple authentic opening FIFO equity/raw assets, full original WIP, accepted finished goods and all genuine customer invoice COGS posted within one DB transaction. Frozen original consumption and customer source documents unchanged.',
                ['batch_id'=>$source['batch']->id,
                    'original_lot_count'=>count($source['lots']),
                    'opening_asset_total'=>$source['opening_total'],
                    'actual_material_WIP'=>$source['material_total'],
                    'remaining_original_raw'=>$source['remaining_total'],
                    'wip_journal_id'=>$issue->id,
                    'finished_journal_id'=>$finished->id,
                    'cost_posting_id'=>$costId,
                    'invoiced_COGS_lines'=>count($source['invoice_line_ids'])]);
            return DB::table('finance_historical_fifo_multi_recoveries')
                ->where('id',$caseId)->first();
        });
    }

    private function journalMatches(?int $id,int $currencyId,string $date,
        int $debit,int $credit,string $amount):bool
    {
        if(!$id || $debit===$credit)return false;
        $journal=DB::table('journal_entries')->where('id',$id)->first();
        if(!$journal || $journal->status!=='posted' ||
            $journal->reversal_of_id ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists() ||
            (int)$journal->currency_id!==$currencyId ||
            (string)$journal->entry_date!==$date)
            return false;
        $lines=DB::table('journal_lines')->where('journal_entry_id',$id)->get();
        if($lines->count()!==2)return false;
        $dr=BigDecimal::zero();$cr=BigDecimal::zero();
        foreach($lines as $line){
            if((int)$line->ledger_account_id===$debit)
                $dr=$dr->plus((string)$line->debit)
                    ->minus((string)$line->credit);
            elseif((int)$line->ledger_account_id===$credit)
                $cr=$cr->plus((string)$line->credit)
                    ->minus((string)$line->debit);
            else return false;
        }
        return $dr->isEqualTo($amount)&&$cr->isEqualTo($amount);
    }

    /** Read-only source-linked audit after original lot is genuinely valued. */
    public function auditPosted(int $caseId):bool
    {
        $c=DB::table('finance_historical_fifo_multi_recoveries')
            ->where('id',$caseId)->first();
        if(!$c || $c->status!=='posted' || !$c->approved_by ||
            !$c->executed_by || !$c->wip_journal_id ||
            !$c->finished_journal_id || !$c->production_batch_cost_posting_id)
            return false;
        $lots=DB::table('finance_historical_fifo_multi_recovery_lots')
            ->where('finance_historical_fifo_multi_recovery_id',$caseId)->get();
        if($lots->count()!==(int)$c->source_lot_count || $lots->count()<2)
            return false;
        $batch=DB::table('production_batches')
            ->where('id',$c->production_batch_id)->first();
        $posting=DB::table('production_batch_cost_postings')
            ->where('id',$c->production_batch_cost_posting_id)->first();
        $policy=$posting?DB::table('manufacturing_cost_ledger_policies')
            ->where('id',$posting->manufacturing_cost_ledger_policy_id)->first():null;
        $equity=DB::table('ledger_accounts')
            ->where('id',$c->opening_equity_ledger_id)->first();
        $physicalFinishedReceipt=DB::table('finished_good_movements')
            ->where('production_batch_id',$c->production_batch_id)
            ->where('type','production_receipt')->get();
        $originalProductionSnapshot=DB::table('production_batch_costs')
            ->where('production_batch_id',$c->production_batch_id)->get();
        if(!$batch || $batch->status!=='released' || !$posting ||
            $physicalFinishedReceipt->count()!==1 ||
            (int)$physicalFinishedReceipt->first()->quantity_packages!==
                (int)$batch->accepted_packages ||
            (string)$physicalFinishedReceipt->first()->occurred_on>
                (string)$c->original_finished_date ||
            $originalProductionSnapshot->count()!==1 ||
            $originalProductionSnapshot->first()->currency_id!==null ||
            ($originalProductionSnapshot->first()->material_cost!==null &&
                !BigDecimal::of((string)$originalProductionSnapshot->first()
                    ->material_cost)->isZero()) ||
            DB::table('production_overhead_allocations')
                ->where('production_batch_id',$c->production_batch_id)->exists() ||
            DB::table('production_material_wastes as waste')
                ->join('production_order_materials as material',
                    'material.id','=','waste.production_order_material_id')
                ->where('material.production_order_id',$batch->production_order_id)->exists() ||
            !$policy || !$equity || !$equity->is_active || $equity->is_group ||
            $equity->type!=='equity' ||
            (int)$posting->production_batch_id!==(int)$batch->id ||
            (int)$posting->currency_id!==(int)$c->currency_id ||
            (int)$policy->currency_id!==(int)$c->currency_id ||
            (int)$posting->issue_journal_id!==(int)$c->wip_journal_id ||
            (int)$posting->capitalization_journal_id!==(int)$c->finished_journal_id ||
            (string)$posting->accounting_date!==(string)$c->original_finished_date ||
            (int)$posting->accepted_packages!==(int)$batch->accepted_packages ||
            !BigDecimal::of((string)$posting->material_amount)
                ->isEqualTo((string)$c->source_material_total) ||
            !BigDecimal::of((string)$posting->total_amount)
                ->isEqualTo((string)$c->source_material_total) ||
            !BigDecimal::of((string)$posting->overhead_amount)->isZero() ||
            !BigDecimal::of((string)$posting->abnormal_waste_amount)->isZero() ||
            substr((string)$batch->released_at,0,10)!==
                (string)$c->original_finished_date)
            return false;
        if(!$this->journalMatches((int)$c->wip_journal_id,(int)$c->currency_id,
                (string)$c->original_issue_date,(int)$policy->work_in_progress_ledger_id,
                (int)$policy->material_inventory_ledger_id,
                (string)$c->source_material_total) ||
            !$this->journalMatches((int)$c->finished_journal_id,(int)$c->currency_id,
                (string)$c->original_finished_date,(int)$policy->finished_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (string)$c->source_material_total))
            return false;

        $openingSum=BigDecimal::zero();$wipSum=BigDecimal::zero();
        $remainingSum=BigDecimal::zero();$sourceActors=[];
        $sourceIssueQuantity=BigDecimal::zero();
        $issueIds=[];$physicalMovements=[];
        foreach($lots as $r){
            $lot=DB::table('inventory_cost_layers')
                ->where('id',$r->inventory_cost_layer_id)->first();
            $review=DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$r->finance_historical_fifo_production_cost_review_id)->first();
            $opening=$lot?DB::table('inventory_stock_movements')
                ->where('id',$lot->source_id)->first():null;
            $provenance=$review?DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$review->finance_legacy_fifo_source_review_id)->first():null;
            if(!$lot || !$review || !$opening || !$provenance ||
                $review->status!=='posted' ||
                $provenance->status!=='approved' ||
                $provenance->review_type!=='consumed_provenance' ||
                (int)$review->inventory_cost_layer_id!==(int)$lot->id ||
                (int)$r->inventory_cost_layer_id!==(int)$lot->id ||
                (int)$review->currency_id!==(int)$c->currency_id ||
                (int)$lot->currency_id!==(int)$c->currency_id ||
                (string)$lot->currency_code!==(string)DB::table('currencies')
                    ->where('id',$c->currency_id)->value('code') ||
                $opening->type!=='opening' || $opening->direction!=='in' ||
                (int)$opening->inventory_material_id!==
                    (int)$lot->inventory_material_id ||
                !BigDecimal::of((string)$opening->quantity)
                    ->isEqualTo((string)$lot->original_quantity) ||
                !BigDecimal::of((string)$provenance->opening_quantity)
                    ->isEqualTo((string)$lot->original_quantity) ||
                (string)$opening->occurred_on!==(string)$r->original_opening_date ||
                $lot->source_type!=='inventory_stock_movement' ||
                (int)$lot->source_id!==(int)$opening->id ||
                !BigDecimal::of((string)$lot->original_cost)
                    ->isEqualTo((string)$r->original_source_total) ||
                !BigDecimal::of((string)$review->original_lot_cost)
                    ->isEqualTo((string)$r->original_source_total) ||
                !BigDecimal::of((string)$review->consumed_production_cost)
                    ->isEqualTo((string)$r->consumed_wip_cost) ||
                !BigDecimal::of((string)$review->remaining_inventory_cost)
                    ->isEqualTo((string)$r->remaining_raw_cost) ||
                !BigDecimal::of((string)$r->original_source_total)
                    ->isEqualTo(BigDecimal::of((string)$r->consumed_wip_cost)
                        ->plus((string)$r->remaining_raw_cost)) ||
                !$this->journalMatches((int)$r->opening_journal_id,
                    (int)$c->currency_id,(string)$r->original_opening_date,
                    (int)$policy->material_inventory_ledger_id,
                    (int)$c->opening_equity_ledger_id,
                    (string)$r->original_source_total))
                return false;
            $sourceActors=array_merge($sourceActors,[
                (int)$opening->created_by,(int)$provenance->requested_by,
                (int)$provenance->reviewed_by,
                (int)$review->submitted_by,(int)$review->approved_by,
            ]);
            $issues=DB::table('finance_historical_fifo_production_cost_lines')
                ->where('finance_historical_fifo_production_cost_review_id',$review->id)->get();
            foreach($issues as $issue){
                $consumption=DB::table('inventory_cost_consumptions')
                    ->where('id',$issue->inventory_cost_consumption_id)->first();
                $physical=$consumption?DB::table('inventory_stock_movements')
                    ->where('id',$consumption->inventory_stock_movement_id)->first():null;
                if(!$consumption || !$physical ||
                    isset($issueIds[$issue->inventory_cost_consumption_id]) ||
                    (int)$consumption->inventory_cost_layer_id!==(int)$lot->id ||
                    (int)$issue->historical_wip_journal_id!==(int)$c->wip_journal_id ||
                    $consumption->is_valued || $consumption->amount!==null ||
                    (int)$issue->production_batch_id!==(int)$batch->id ||
                    (string)$physical->occurred_on!==(string)$c->original_issue_date ||
                    (string)$issue->original_material_issue_date!==
                        (string)$c->original_issue_date ||
                    $physical->reference_type!=='production_order_material' ||
                    $physical->type!=='issue' || $physical->direction!=='out' ||
                    (int)$physical->reference_id!==(int)$issue->production_order_material_id ||
                    !BigDecimal::of((string)$consumption->quantity)
                        ->isEqualTo((string)$issue->quantity))
                    return false;
                $issueIds[$issue->inventory_cost_consumption_id]=true;
                $physicalMovements[(int)$physical->id]=
                    ($physicalMovements[(int)$physical->id]??BigDecimal::zero())
                        ->plus((string)$issue->quantity);
                $wipSum=$wipSum->plus((string)$issue->proposed_original_wip_cost);
                $sourceIssueQuantity=$sourceIssueQuantity
                    ->plus((string)$issue->quantity);
                $sourceActors[]=(int)$physical->created_by;
            }
            $openingSum=$openingSum->plus((string)$r->original_source_total);
            $remainingSum=$remainingSum->plus((string)$r->remaining_raw_cost);
        }
        $sourceMaterialLines=DB::table('production_order_materials')
            ->where('production_order_id',$batch->production_order_id)
            ->pluck('id');
        $currentPhysicalIssues=DB::table('inventory_stock_movements')
            ->where('reference_type','production_order_material')
            ->whereIn('reference_id',$sourceMaterialLines)
            ->where('direction','out')->get();
        if($currentPhysicalIssues->count()!==count($physicalMovements) ||
            !BigDecimal::of((string)$originalProductionSnapshot->first()
                ->unvalued_quantity)->isEqualTo($sourceIssueQuantity))
            return false;
        foreach($currentPhysicalIssues as $movement){
            if(!isset($physicalMovements[(int)$movement->id]) ||
                !BigDecimal::of((string)$movement->quantity)
                    ->isEqualTo($physicalMovements[(int)$movement->id]))
                return false;
        }
        foreach([(int)$c->submitted_by,(int)$c->approved_by,
            (int)$c->executed_by] as $authId){
            if(!$authId || in_array($authId,$sourceActors,true))
                return false;
        }
        if(count(array_unique([
            (int)$c->submitted_by,(int)$c->approved_by,
            (int)$c->executed_by]))!==3 ||
            count($issueIds)!==(int)$c->original_issue_line_count ||
            !$openingSum->isEqualTo((string)$c->original_opening_total) ||
            !$wipSum->isEqualTo((string)$c->source_material_total) ||
            !$remainingSum->isEqualTo((string)$c->remaining_original_raw_total) ||
            !$openingSum->isEqualTo($wipSum->plus($remainingSum)))
            return false;

        $sales=DB::table('sales_invoice_lines as sold')
            ->join('finished_good_dispatch_lines as physical',
                'physical.finished_good_dispatch_id','=','sold.dispatch_id')
            ->join('finished_good_dispatches as head',
                'head.id','=','sold.dispatch_id')
            ->where('sold.production_batch_id',$c->production_batch_id)
            ->orderBy('head.dispatch_date')->orderBy('physical.id')
            ->select('sold.*')->get();
        $postedCOGS=DB::table('production_invoice_cogs_postings as cost')
            ->join('sales_invoice_lines as line',
                'line.id','=','cost.sales_invoice_line_id')
            ->where('line.production_batch_id',$c->production_batch_id)
            ->get(['cost.*']);
        if($sales->count()!==(int)$c->genuine_invoice_line_count ||
            $postedCOGS->count()!==$sales->count() ||
            DB::table('finished_good_dispatch_lines')
                ->where('production_batch_id',$batch->id)->count()!==$sales->count() ||
            DB::table('sales_credit_note_lines')
                ->where('production_batch_id',$batch->id)->exists() ||
            DB::table('sales_return_quarantines')
                ->where('production_batch_id',$batch->id)->exists())
            return false;
        $costTotal=BigDecimal::zero();$packages=0;$realDispatches=[];
        foreach($sales as $sale){
            $costed=$postedCOGS->firstWhere('sales_invoice_line_id',$sale->id);
            $invoice=DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first();
            $physical=DB::table('finished_good_dispatch_lines as physical')
                ->join('finished_good_dispatches as dispatch',
                    'dispatch.id','=','physical.finished_good_dispatch_id')
                ->where('physical.finished_good_dispatch_id',$sale->dispatch_id)
                ->select('physical.*','dispatch.dispatch_date')->first();
            $nextQty=$packages+(int)$sale->quantity_packages;
            $expectedCost=$nextQty===(int)$batch->accepted_packages
                ?BigDecimal::of((string)$c->source_material_total)
                    ->minus($costTotal)
                :BigDecimal::of((string)$c->source_material_total)
                    ->multipliedBy((string)$sale->quantity_packages)
                    ->dividedBy((string)$batch->accepted_packages,4,
                        RoundingMode::HalfUp);
            if(!$costed || !$invoice || !$physical ||
                isset($realDispatches[(int)$sale->dispatch_id]) ||
                !$expectedCost->isPositive() ||
                !BigDecimal::of((string)$costed->amount)
                    ->isEqualTo($expectedCost) ||
                (int)$costed->production_batch_cost_posting_id!==(int)$posting->id ||
                (int)$costed->quantity_packages!==(int)$sale->quantity_packages ||
                !$this->journalMatches((int)$costed->journal_entry_id,
                    (int)$c->currency_id,(string)$invoice->invoice_date,
                    (int)$policy->cogs_ledger_id,
                    (int)$policy->finished_inventory_ledger_id,
                    (string)$costed->amount))
                return false;
            try{
                app(HistoricalProductionFifoRecoveryService::class)
                    ->verifiedCustomerInvoiceSource($physical,$sale,$invoice);
            }catch(ValidationException $e){return false;}
            $realDispatches[(int)$sale->dispatch_id]=true;
            $costTotal=$costTotal->plus((string)$costed->amount);
            $packages+=(int)$sale->quantity_packages;
        }
        return $packages<=(int)$batch->accepted_packages &&
            $costTotal->isLessThanOrEqualTo((string)$c->source_material_total);
    }
}
