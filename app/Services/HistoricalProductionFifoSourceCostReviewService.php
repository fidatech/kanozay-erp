<?php

namespace App\Services;

use App\Models\ActivityLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only FINANCIAL PREFLIGHT for source-backed production issues of
 * originally unvalued opening FIFO stock. An approval freezes evidence and
 * expected WIP allocation, NOT an accounting posting. Old production-cost
 * snapshots are immutable; historical WIP -> finished inventory -> invoiced
 * COGS must eventually post in one separately authorized atomic executor.
 */
final class HistoricalProductionFifoSourceCostReviewService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_production_fifo'=>$message]);
    }

    private function source(int $provenanceId): array
    {
        $review=DB::table('finance_legacy_fifo_source_reviews')
            ->where('id',$provenanceId)->first();
        abort_unless($review,404);
        if($review->review_type!=='consumed_provenance' ||
            $review->status!=='approved' ||
            !$review->reviewed_by ||
            (int)$review->requested_by===(int)$review->reviewed_by)
            $this->fail('A separately authorized, genuinely approved original opening FIFO consumption provenance review is required before a production WIP cost proposal.');

        $original=app(LegacyFifoSourceReviewService::class)
            ->snapshot((int)$review->inventory_cost_layer_id);
        if(!hash_equals((string)$review->source_fingerprint,$original['fingerprint']) ||
            !$original['consumed']->isPositive() ||
            $original['layer']->original_cost!==null ||
            $original['layer']->currency_id!==null ||
            $original['layer']->currency_code!==null ||
            !BigDecimal::of((string)$review->opening_quantity)
                ->isEqualTo($original['original']) ||
            !BigDecimal::of((string)$review->consumed_quantity)
                ->isEqualTo($original['consumed']) ||
            !BigDecimal::of((string)$review->remaining_quantity)
                ->isEqualTo($original['remaining']) ||
            (int)$review->inventory_material_id!==(int)$original['material']->id)
            $this->fail('The genuine original physical FIFO receipt, dated issues or existing stock changed after independently approved provenance evidence.');

        $layerId=(int)$original['layer']->id;
        if(DB::table('finance_opening_fifo_valuations')
                ->where('inventory_cost_layer_id',$layerId)->exists() ||
            DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('inventory_cost_layer_id',$layerId)->exists() ||
            DB::table('finance_historical_correction_cases')
                ->where('case_type','opening_fifo_unvalued')
                ->where('source_id',$layerId)
                ->whereIn('status',['submitted','approved'])->exists())
            $this->fail('Opening FIFO receipt already belongs to another financial cost proposal or unresolved original asset/equity case. Duplicate valuation is prohibited.');

        if($original['consumptions']->count()>80)
            $this->fail('This production-opening lot has too many independent source consumption rows for one source-reviewed reconstruction; split only through an independently approved multi-lot workflow.');
        $issues=[];$sourceActors=[
            (int)$original['opening']->created_by,
            (int)$review->requested_by,(int)$review->reviewed_by,
        ];
        $snapshotBatches=[];
        $lineMovements=[];
        foreach($original['consumptions'] as $row){
            if($row->reference_type!=='production_order_material' ||
                $row->type!=='issue' || $row->direction!=='out' ||
                $row->amount!==null || $row->is_valued ||
                $row->currency_id!==null || $row->currency_code!==null)
                $this->fail('All historical physical FIFO issues of this source lot must be genuinely unvalued production-order material issues. Stock adjustments, supplier returns and mixed-purpose lots require their own source-type financial controls.');

            $material=DB::table('production_order_materials')
                ->where('id',$row->reference_id)->first();
            $order=$material?DB::table('production_orders')
                ->where('id',$material->production_order_id)->first():null;
            $batches=$order?DB::table('production_batches')
                ->where('production_order_id',$order->id)->get():collect();
            $batch=$batches->count()===1?$batches->first():null;
            $receipts=$batch?DB::table('finished_good_movements')
                ->where('production_batch_id',$batch->id)
                ->where('type','production_receipt')->get():collect();
            $receipt=$receipts->count()===1?$receipts->first():null;
            if(!$material || !$order || !$batch || !$receipt ||
                !$batch->released_at ||
                (string)$receipt->occurred_on<
                    substr((string)$row->occurred_on,0,10) ||
                (string)$receipt->occurred_on>
                    substr((string)$batch->released_at,0,10) ||
                (int)$receipt->quantity_packages!==(int)$batch->accepted_packages ||
                (int)$material->inventory_material_id!==(int)$original['material']->id ||
                (int)$row->inventory_material_id!==(int)$original['material']->id ||
                !$material->issued_quantity ||
                $order->status==='cancelled' ||
                $batch->status!=='released' ||
                (int)$batch->accepted_packages<1 ||
                substr((string)$row->occurred_on,0,10)>
                    substr((string)$batch->released_at,0,10) ||
                (string)$row->occurred_on<$original['layer']->received_on->toDateString())
                $this->fail('Every historic unvalued production issue must identify the same original material, actual issued production-order line and a later independently QC-released production batch.');

            if((int)$review->requested_by===(int)$row->created_by ||
                (int)$review->reviewed_by===(int)$row->created_by)
                $this->fail('Original production material issuer cannot independently request or approve their own opening FIFO source-provenance evidence.');

            $consumption=DB::table('inventory_cost_consumptions')
                ->where('inventory_stock_movement_id',$row->inventory_stock_movement_id)
                ->get(['quantity']);
            $all=BigDecimal::zero();
            foreach($consumption as $part)$all=$all->plus((string)$part->quantity);
            if(!$all->isEqualTo((string)$row->movement_quantity))
                $this->fail('Genuine original physical production issue quantity differs from all existing FIFO cost consumption source lines.');
            $lineMovements[(int)$material->id]=true;

            $batchId=(int)$batch->id;
            if(!array_key_exists($batchId,$snapshotBatches)){
                $costSnapshot=DB::table('production_batch_costs')
                    ->where('production_batch_id',$batchId)->get();
                if($costSnapshot->count()!==1 ||
                    !BigDecimal::of((string)$costSnapshot->first()->unvalued_quantity)
                        ->isPositive() ||
                    DB::table('production_batch_cost_postings')
                        ->where('production_batch_id',$batchId)->exists() ||
                    DB::table('production_invoice_cogs_postings as cogs')
                        ->join('sales_invoice_lines as sale',
                            'sale.id','=','cogs.sales_invoice_line_id')
                        ->where('sale.production_batch_id',$batchId)->exists())
                    $this->fail('Original manufacturing batch must have one immutable unvalued cost snapshot, no previous capitalization and no already posted invoice COGS. Fully costed batches cannot accept additional opening FIFO WIP.');
                $dispatches=DB::table('finished_good_dispatch_lines')
                    ->where('production_batch_id',$batchId)->get();
                $invoices=DB::table('sales_invoice_lines')
                    ->where('production_batch_id',$batchId)->get();
                $overheads=DB::table('production_overhead_allocations')
                    ->where('production_batch_id',$batchId)->orderBy('id')->get();
                $wastes=DB::table('production_material_wastes as waste')
                    ->join('production_order_materials as material',
                        'material.id','=','waste.production_order_material_id')
                    ->where('material.production_order_id',$order->id)
                    ->select('waste.*')->orderBy('waste.id')->get();
                $credited=DB::table('sales_credit_note_lines')
                    ->where('production_batch_id',$batchId)->orderBy('id')->get();
                $qualityReturns=DB::table('sales_return_quarantines')
                    ->where('production_batch_id',$batchId)->orderBy('id')->get();
                $snapshotBatches[$batchId]=[
                    'batch'=>[$batch->id,$batch->batch_number,$batch->status,
                        $batch->production_order_id,$batch->accepted_packages,
                        $batch->released_at,$batch->finished_good_id],
                    'actual_finished_receipt'=>[
                        $receipt->id,$receipt->occurred_on,
                        $receipt->quantity_packages,$receipt->created_by,
                    ],
                    'order'=>[$order->id,$order->number,$order->status],
                    'immutable_cost'=>$costSnapshot->toArray(),
                    'already_physical_dispatched'=>$dispatches->toArray(),
                    'genuine_customer_sale_lines'=>$invoices->toArray(),
                    'original_production_overhead'=>$overheads->toArray(),
                    'original_material_waste'=>$wastes->toArray(),
                    'actual_customer_credits'=>$credited->toArray(),
                    'signed_customer_QC_returns'=>$qualityReturns->toArray(),
                ];
            }
            $sourceActors[]=(int)$row->created_by;
            $issues[]=[
                'consumption_id'=>(int)$row->id,
                'movement_id'=>(int)$row->inventory_stock_movement_id,
                'material_line_id'=>(int)$material->id,
                'production_batch_id'=>$batchId,
                'date'=>substr((string)$row->occurred_on,0,10),
                'quantity'=>(string)BigDecimal::of((string)$row->quantity)->toScale(6),
                'source'=>[
                    $row->id,$row->inventory_stock_movement_id,$row->quantity,
                    $row->company_warehouse_id,$row->occurred_on,$row->created_by,
                    $row->reference_type,$row->reference_id,
                    $material->id,$material->issued_quantity,$material->is_packaging,
                    $order->id,$order->number,$batch->id,$batch->status,
                ],
            ];
        }
        if(!$issues || count($snapshotBatches)>25)
            $this->fail('The genuine original opening FIFO source is empty or spans too many production batches for a single independent historic-cost review.');

        $sourceQtyByBatch=[];
        foreach($issues as $issue){
            $batchId=$issue['production_batch_id'];
            $sourceQtyByBatch[$batchId]=
                ($sourceQtyByBatch[$batchId]??BigDecimal::zero())
                    ->plus($issue['quantity']);
        }
        foreach($sourceQtyByBatch as $batchId=>$quantity){
            $snapshot=$snapshotBatches[$batchId]['immutable_cost'][0]??null;
            if(!$snapshot ||
                $quantity->isGreaterThan((string)$snapshot->unvalued_quantity))
                $this->fail('Source-reviewed opening FIFO production quantities exceed the actual permanently frozen unvalued material quantity for a released batch; the proposed WIP cannot be double-counted.');
        }

        foreach(array_keys($lineMovements) as $materialLineId){
            $sourceMoves=DB::table('inventory_stock_movements')
                ->where('reference_type','production_order_material')
                ->where('reference_id',$materialLineId)
                ->where('direction','out')->get();
            $all=BigDecimal::zero();
            foreach($sourceMoves as $move)$all=$all->plus((string)$move->quantity);
            $expected=DB::table('production_order_materials')
                ->where('id',$materialLineId)->value('issued_quantity');
            if(!$all->isEqualTo((string)$expected))
                $this->fail('Actual historic production-order material issued quantity has missing or duplicate physical stock issue source movements.');
        }

        // The owner and physical source of existing remaining stock must
        // be evidenced. No unlocated or imaginary warehouse asset.
        if($original['remaining']->isPositive()){
            if(!$original['located']->isEqualTo($original['remaining']))
                $this->fail('Remaining historical opening lot must be fully attributed to a physically verified warehouse before a production WIP source-cost review.');
            foreach($original['positions'] as $position){
                if(BigDecimal::of((string)$position->quantity)->isPositive()){
                    $balance=$original['balances']->firstWhere(
                        'company_warehouse_id',$position->company_warehouse_id);
                    if(!$position->company_warehouse_id || !$balance ||
                        BigDecimal::of((string)$balance->quantity)
                            ->isLessThan((string)$position->quantity))
                        $this->fail('A remaining opening stock position is still unassigned or exceeds its actual physical warehouse balance.');
                }
            }
        }
        usort($issues,fn($a,$b)=>[$a['date'],$a['movement_id'],$a['consumption_id']]
            <=>[$b['date'],$b['movement_id'],$b['consumption_id']]);
        ksort($snapshotBatches,SORT_NUMERIC);
        return [
            'review'=>$review,'original'=>$original,
            'issues'=>$issues,'batches'=>$snapshotBatches,
            'source_actors'=>array_values(array_unique(array_filter($sourceActors))),
            'physical_fingerprint'=>hash('sha256',json_encode([
                'source_fifo'=>$original['fingerprint'],
                'production_batches'=>$snapshotBatches,
                'production_issues'=>array_map(fn($issue)=>$issue['source'],$issues),
            ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function originalCost(int $provenanceId,array $data):array
    {
        $proof=$this->source($provenanceId);
        $currency=DB::table('currencies')->where('id',$data['currency_id'])
            ->where('is_active',true)->first();
        if(!$currency)
            $this->fail('The original purchase/ownership cost currency must be an active and identifiable original source currency.');
        $policy=app(ManufacturingLedgerPolicyService::class)->approved((int)$currency->id);
        $cost=BigDecimal::of((string)$data['original_lot_cost'])
            ->toScale(4,RoundingMode::Unnecessary);
        if(!$cost->isPositive() || $cost->isGreaterThan('9999999999999999.9999'))
            $this->fail('Documented original production FIFO opening cost must be positive and expressed in its authentic source currency, without guessed conversions.');

        $quantity=$proof['original']['original'];
        $remaining=$proof['original']['remaining'];
        $remainingCost=$remaining->isZero()?BigDecimal::zero()
            :$cost->multipliedBy($remaining)
                ->dividedBy($quantity,4,RoundingMode::HalfUp);
        $consumedCost=$cost->minus($remainingCost);
        if(!$consumedCost->isPositive() ||
            ($remaining->isPositive() && !$remainingCost->isPositive()))
            $this->fail('This 4-decimal source currency cannot represent both the genuine remaining stock value and every original production material issue share.');

        $allocated=BigDecimal::zero();$lines=[];
        foreach($proof['issues'] as $i=>$issue){
            $last=$i===count($proof['issues'])-1;
            $amount=$last?$consumedCost->minus($allocated)
                :$cost->multipliedBy($issue['quantity'])
                    ->dividedBy($quantity,4,RoundingMode::HalfUp);
            if(!$amount->isPositive() ||
                $amount->isGreaterThan($consumedCost->minus($allocated)))
                $this->fail('Original FIFO cost cannot assign positive source-currency WIP to all genuinely consumed production material issues; do not fabricate lost rounding value.');
            $allocated=$allocated->plus($amount);
            $issue['proposed_wip_cost']=(string)$amount->toScale(4);
            $issue['fingerprint']=hash('sha256',json_encode([
                'source'=>$issue['source'],
                'physical_fingerprint'=>$proof['physical_fingerprint'],
                'source_cost'=>(string)$cost,
                'source_currency_id'=>$currency->id,
                'proposed_original_cost'=>$issue['proposed_wip_cost'],
                'raw_asset_ledger'=>$policy->material_inventory_ledger_id,
                'wip_ledger'=>$policy->work_in_progress_ledger_id,
            ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
            $lines[]=$issue;
        }
        if(!$allocated->isEqualTo($consumedCost) ||
            !$allocated->plus($remainingCost)->isEqualTo($cost))
            $this->fail('The original source opening asset, remaining inventory and proposed historical WIP cost allocations do not reconcile exactly.');

        $fingerprint=hash('sha256',json_encode([
            'physical_source'=>$proof['physical_fingerprint'],
            'provenance_review'=>$provenanceId,
            'currency'=>$currency->id,
            'original_cost'=>(string)$cost,
            'remaining_cost'=>(string)$remainingCost->toScale(4),
            'consumed_cost'=>(string)$consumedCost->toScale(4),
            'inventory_ledger'=>$policy->material_inventory_ledger_id,
            'wip_ledger'=>$policy->work_in_progress_ledger_id,
            'lines'=>$lines,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'proof'=>$proof,'currency'=>$currency,'policy'=>$policy,
            'original_cost'=>(string)$cost,
            'remaining_cost'=>(string)$remainingCost->toScale(4),
            'consumed_cost'=>(string)$consumedCost->toScale(4),
            'fingerprint'=>$fingerprint,'lines'=>$lines,
        ];
    }

    private function independent(array $proof,int $userId,?object $plan=null):void
    {
        $actors=$proof['source_actors'];
        if($plan)$actors[]=(int)$plan->submitted_by;
        if(in_array($userId,$actors,true))
            $this->fail('Original opening registrar, historic production material issuers, consumption provenance actors and independently reviewing source-cost accountants must be distinct.');
    }

    private function unchanged(object $plan):array
    {
        $state=$this->originalCost((int)$plan->finance_legacy_fifo_source_review_id,[
            'currency_id'=>$plan->currency_id,
            'original_lot_cost'=>$plan->original_lot_cost,
        ]);
        if((int)$plan->inventory_cost_layer_id!==
                (int)$state['proof']['original']['layer']->id ||
            (int)$plan->source_inventory_ledger_id!==
                (int)$state['policy']->material_inventory_ledger_id ||
            (int)$plan->target_wip_ledger_id!==
                (int)$state['policy']->work_in_progress_ledger_id ||
            !BigDecimal::of((string)$plan->consumed_production_cost)
                ->isEqualTo($state['consumed_cost']) ||
            !BigDecimal::of((string)$plan->remaining_inventory_cost)
                ->isEqualTo($state['remaining_cost']) ||
            !hash_equals((string)$plan->source_fingerprint,$state['fingerprint']))
            $this->fail('Authentic original production issue, manufacturing dependency, currency, cost allocation, ledger policy or frozen source changed since signed finance proposal.');

        $recorded=DB::table('finance_historical_fifo_production_cost_lines')
            ->where('finance_historical_fifo_production_cost_review_id',$plan->id)
            ->get()->keyBy('inventory_cost_consumption_id');
        if($recorded->count()!==count($state['lines']))
            $this->fail('The source production WIP allocation register has missing or unexpected original FIFO issues.');
        foreach($state['lines'] as $line){
            $row=$recorded->get($line['consumption_id']);
            if(!$row || (int)$row->inventory_stock_movement_id!==$line['movement_id'] ||
                (int)$row->production_order_material_id!==$line['material_line_id'] ||
                (int)$row->production_batch_id!==$line['production_batch_id'] ||
                (string)$row->original_material_issue_date!==$line['date'] ||
                !BigDecimal::of((string)$row->quantity)->isEqualTo($line['quantity']) ||
                !BigDecimal::of((string)$row->proposed_original_wip_cost)
                    ->isEqualTo($line['proposed_wip_cost']) ||
                !hash_equals((string)$row->source_fingerprint,$line['fingerprint']))
                $this->fail('Original material issue, production batch or exact proposed historical WIP amount no longer matches separately reviewed source evidence.');
        }
        return $state;
    }

    /**
     * Strict pre-post access for a separately signed original-source WIP
     * recovery. Does not post or alter any immutable production snapshot.
     */
    public function verifiedApproved(int $planId):array
    {
        $plan=DB::table('finance_historical_fifo_production_cost_reviews')
            ->where('id',$planId)->first();
        if(!$plan || $plan->status!=='approved' ||
            !$plan->submitted_by || !$plan->approved_by ||
            (int)$plan->submitted_by===(int)$plan->approved_by ||
            !$plan->original_cost_evidence_reference ||
            !$plan->original_cost_evidence_notes ||
            !$plan->approval_reference || !$plan->approval_notes)
            $this->fail('Original source production FIFO WIP needs a complete signed independently approved proposal before any downstream capitalized stock or COGS reconstruction.');
        $state=$this->unchanged($plan);
        $actors=$state['proof']['source_actors'];
        if(in_array((int)$plan->submitted_by,$actors,true) ||
            in_array((int)$plan->approved_by,$actors,true))
            $this->fail('Original opening and production source actors cannot approve their own financial WIP valuation.');
        return ['plan'=>$plan,'state'=>$state,
            'actors'=>array_values(array_unique(array_filter(
                array_merge($actors,[(int)$plan->submitted_by,(int)$plan->approved_by])
            )))];
    }

    /**
     * Finance-close/read-only verification. A source-reviewed plan is NOT
     * GL posting: it must remain a blocker until the combined raw/WIP/FG/
     * COGS historical executor is separately implemented and verified.
     */
    public function audit(int $planId):bool
    {
        $plan=DB::table('finance_historical_fifo_production_cost_reviews')
            ->where('id',$planId)->first();
        if(!$plan)return false;
        if($plan->status==='posted'){
            $recovery=DB::table('finance_historical_fifo_production_recoveries')
                ->where('finance_historical_fifo_production_cost_review_id',
                    $plan->id)->first();
            return $recovery && app(HistoricalProductionFifoRecoveryService::class)
                ->auditPosted((int)$recovery->id);
        }
        if(!in_array($plan->status,['submitted','approved','rejected'],true))
            return false;
        try{
            $state=$this->unchanged($plan);
        }catch(ValidationException $e){
            return false;
        }
        if($plan->status==='approved'){
            if(!$plan->approved_by ||
                (int)$plan->submitted_by===(int)$plan->approved_by ||
                !$plan->approval_reference || !$plan->approval_notes ||
                in_array((int)$plan->approved_by,$state['proof']['source_actors'],true))
                return false;
        }
        return true;
    }

    public function submit(int $provenanceId,array $data,int $userId):object
    {
        return DB::transaction(function()use($provenanceId,$data,$userId){
            $review=DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$provenanceId)->first();
            abort_unless($review,404);
            DB::table('inventory_materials')->where('id',$review->inventory_material_id)
                ->lockForUpdate()->first();
            DB::table('inventory_cost_layers')->where('id',$review->inventory_cost_layer_id)
                ->lockForUpdate()->first();
            $state=$this->originalCost($provenanceId,$data);
            $this->independent($state['proof'],$userId);
            if(DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('inventory_cost_layer_id',$review->inventory_cost_layer_id)->exists())
                $this->fail('This consumed production opening source already has a separate signed WIP reconstruction proposal. Do not duplicate or silently amend the original historic source.');
            $id=DB::table('finance_historical_fifo_production_cost_reviews')->insertGetId([
                'inventory_cost_layer_id'=>$review->inventory_cost_layer_id,
                'finance_legacy_fifo_source_review_id'=>$provenanceId,
                'status'=>'submitted','currency_id'=>$state['currency']->id,
                'source_inventory_ledger_id'=>$state['policy']->material_inventory_ledger_id,
                'target_wip_ledger_id'=>$state['policy']->work_in_progress_ledger_id,
                'original_lot_cost'=>$state['original_cost'],
                'remaining_inventory_cost'=>$state['remaining_cost'],
                'consumed_production_cost'=>$state['consumed_cost'],
                'original_quantity'=>(string)$state['proof']['original']['original']->toScale(6),
                'consumed_quantity'=>(string)$state['proof']['original']['consumed']->toScale(6),
                'remaining_quantity'=>(string)$state['proof']['original']['remaining']->toScale(6),
                'source_fingerprint'=>$state['fingerprint'],
                'original_cost_evidence_reference'=>trim($data['original_cost_evidence_reference']),
                'original_cost_evidence_notes'=>trim($data['original_cost_evidence_notes']),
                'submitted_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($state['lines'] as $line)
                DB::table('finance_historical_fifo_production_cost_lines')->insert([
                    'finance_historical_fifo_production_cost_review_id'=>$id,
                    'inventory_cost_consumption_id'=>$line['consumption_id'],
                    'inventory_stock_movement_id'=>$line['movement_id'],
                    'production_order_material_id'=>$line['material_line_id'],
                    'production_batch_id'=>$line['production_batch_id'],
                    'original_material_issue_date'=>$line['date'],
                    'quantity'=>$line['quantity'],
                    'proposed_original_wip_cost'=>$line['proposed_wip_cost'],
                    'source_fingerprint'=>$line['fingerprint'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_fifo_production_source.submitted',
                'finance_historical_fifo_production_cost_review',$id,
                'Original production FIFO issue and projected original WIP source costs submitted. No immutable batch snapshots, physical stock, inventory asset or GL journals altered.');
            return DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$id)->first();
        });
    }

    public function approve(int $planId,array $data,int $userId):object
    {
        return DB::transaction(function()use($planId,$data,$userId){
            $plan=DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$planId)->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='submitted')
                $this->fail('Only a submitted and previously unapproved production original-source cost proposal may be independently reviewed.');
            $state=$this->unchanged($plan);
            $this->independent($state['proof'],$userId,$plan);
            DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$plan->id)->update([
                    'status'=>$data['decision']==='approve'?'approved':'rejected',
                    'approval_reference'=>trim($data['approval_reference']),
                    'approval_notes'=>trim($data['approval_notes']),
                    'approved_by'=>$userId,'approved_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_fifo_production_source.'.
                ($data['decision']==='approve'?'approved':'rejected'),
                'finance_historical_fifo_production_cost_review',$plan->id,
                'Original source-currency production WIP cost preflight independently reviewed. It is a DOCUMENTARY PLAN ONLY until a single original-period-gated WIP / finished inventory / COGS recovery is implemented; no GL was posted.');
            return DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$planId)->first();
        });
    }
}
