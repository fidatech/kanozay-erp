<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Independently witnessed legacy FIFO source evidence.
 *
 * consumed_provenance: documentary only; never invents consumed layer
 * cost or rewrites immutable FIFO issues.
 *
 * locate_remaining: assign a real existing portion to a physically
 * counted warehouse without changing on-hand stock, source quantity,
 * historic consumption or GL. Review locks the material and source lot.
 */
final class LegacyFifoSourceReviewService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['legacy_fifo'=>$message]);
    }

    public function snapshot(int $layerId): array
    {
        $lot=InventoryCostLayer::whereKey($layerId)->firstOrFail();
        $material=InventoryMaterial::whereKey($lot->inventory_material_id)->firstOrFail();
        if($lot->source_type!=='inventory_stock_movement')
            $this->fail('Only genuine original physical opening FIFO layers may enter the source provenance register.');
        $valued=$lot->original_cost!==null &&
            $lot->currency_id!==null && $lot->currency_code!==null;
        $unvalued=$lot->original_cost===null &&
            $lot->currency_id===null && $lot->currency_code===null;
        if(!$valued && !$unvalued)
            $this->fail('Partial original FIFO cost and currency attribution is inconsistent. Reconcile that source document before reviewing physical warehouse location.');
        $opening=DB::table('inventory_stock_movements')->where('id',$lot->source_id)->first();
        if(!$opening || !$opening->created_by ||
            $opening->type!=='opening' || $opening->direction!=='in' ||
            (int)$opening->inventory_material_id!==(int)$lot->inventory_material_id ||
            (string)$opening->occurred_on!==$lot->received_on->toDateString() ||
            !BigDecimal::of((string)$opening->quantity)
                ->isEqualTo((string)$lot->original_quantity))
            $this->fail('Missing original dated and quantity-matched physical opening-stock receipt.');

        $consumptions=DB::table('inventory_cost_consumptions as cost')
            ->join('inventory_stock_movements as movement',
                'movement.id','=','cost.inventory_stock_movement_id')
            ->where('cost.inventory_cost_layer_id',$lot->id)
            ->orderBy('cost.id')
            ->get(['cost.id','cost.inventory_stock_movement_id',
                'cost.inventory_cost_layer_id','cost.quantity','cost.amount',
                'cost.is_valued','cost.currency_id','cost.currency_code',
                'movement.inventory_material_id','movement.quantity as movement_quantity',
                'movement.direction','movement.type','movement.company_warehouse_id',
                'movement.reference_type','movement.reference_id',
                'movement.occurred_on','movement.created_by']);
        $historicalRecognitions=DB::table('finance_historical_fifo_adjustment_cost_lines as used')
            ->join('finance_historical_fifo_adjustment_valuations as case',
                'case.id','=','used.finance_historical_fifo_adjustment_valuation_id')
            ->leftJoin('journal_entries as journal','journal.id','=','used.journal_entry_id')
            ->where('case.inventory_cost_layer_id',$lot->id)
            ->where('case.status','posted')
            ->get(['used.id','used.inventory_cost_consumption_id',
                'used.inventory_stock_movement_id','used.original_issue_date',
                'used.quantity','used.amount','used.journal_entry_id',
                'case.currency_id','case.original_total_cost',
                'journal.status as journal_status',
                'journal.reversal_of_id','journal.entry_date',
                'journal.currency_id as journal_currency_id'])
            ->keyBy('inventory_cost_consumption_id');
        $productionRecognitions=DB::table('finance_historical_fifo_production_cost_lines as used')
            ->join('finance_historical_fifo_production_cost_reviews as source',
                'source.id','=','used.finance_historical_fifo_production_cost_review_id')
            ->leftJoin('finance_historical_fifo_production_recoveries as recovery',
                'recovery.finance_historical_fifo_production_cost_review_id','=','source.id')
            ->leftJoin('finance_historical_fifo_multi_recovery_lots as grouped',
                'grouped.finance_historical_fifo_production_cost_review_id','=','source.id')
            ->leftJoin('finance_historical_fifo_multi_recoveries as multi',
                'multi.id','=','grouped.finance_historical_fifo_multi_recovery_id')
            ->leftJoin('journal_entries as journal',
                'journal.id','=','used.historical_wip_journal_id')
            ->where('source.inventory_cost_layer_id',$lot->id)
            ->where('source.status','posted')
            ->where(function($query){
                $query->where('recovery.status','posted')
                    ->orWhere('multi.status','posted');
            })
            ->get(['used.id','used.inventory_cost_consumption_id',
                'used.inventory_stock_movement_id',
                'used.original_material_issue_date as original_issue_date',
                'used.quantity','used.proposed_original_wip_cost as amount',
                'used.historical_wip_journal_id as journal_entry_id',
                'source.currency_id','source.original_lot_cost as original_total_cost',
                'journal.status as journal_status',
                'journal.reversal_of_id','journal.entry_date',
                'journal.currency_id as journal_currency_id'])
            ->keyBy('inventory_cost_consumption_id');
        $used=BigDecimal::zero();$movementTotals=[];
        foreach($consumptions as $cost){
            $quantity=BigDecimal::of((string)$cost->quantity);
            $recovery=$historicalRecognitions->get($cost->id)
                ??$productionRecognitions->get($cost->id);
            if($historicalRecognitions->has($cost->id) &&
                $productionRecognitions->has($cost->id))
                $this->fail('An original FIFO issue was financially recognized as BOTH adjustment loss and production WIP. No source movement may be expensed twice.');
            $validBackfill=$valued &&
                !$cost->is_valued && $cost->amount===null &&
                $cost->currency_id===null && $cost->currency_code===null &&
                $recovery &&
                $recovery->journal_entry_id &&
                $recovery->journal_status==='posted' && !$recovery->reversal_of_id &&
                (int)$recovery->currency_id===(int)$lot->currency_id &&
                (int)$recovery->journal_currency_id===(int)$lot->currency_id &&
                (int)$recovery->inventory_stock_movement_id===
                    (int)$cost->inventory_stock_movement_id &&
                (string)$recovery->original_issue_date===(string)$cost->occurred_on &&
                (string)$recovery->entry_date===(string)$cost->occurred_on &&
                BigDecimal::of((string)$recovery->quantity)->isEqualTo($quantity) &&
                BigDecimal::of((string)$recovery->original_total_cost)
                    ->isEqualTo((string)$lot->original_cost) &&
                !DB::table('journal_entries')
                    ->where('reversal_of_id',$recovery->journal_entry_id)->exists();
            if(!$quantity->isPositive() || $cost->direction!=='out' ||
                (int)$cost->inventory_material_id!==(int)$material->id ||
                (string)$cost->occurred_on<$lot->received_on->toDateString() ||
                ($unvalued &&
                    ($cost->amount!==null || $cost->is_valued ||
                     $cost->currency_id!==null || $cost->currency_code!==null)) ||
                ($valued && !$validBackfill &&
                    (!$cost->is_valued || $cost->amount===null ||
                     (int)$cost->currency_id!==(int)$lot->currency_id ||
                     (string)$cost->currency_code!==(string)$lot->currency_code)) ||
                !in_array($cost->reference_type,[
                    'production_order_material','purchase_return_line',
                    'stock_adjustment','inventory_adjustment',
                ],true))
                $this->fail('A historical source issue is dated, valued, typed or material-linked incorrectly. Stop: the original FIFO consumption needs independent provenance investigation.');
            $used=$used->plus($quantity);
            $movementId=(int)$cost->inventory_stock_movement_id;
            $movementTotals[$movementId]=($movementTotals[$movementId]??BigDecimal::zero())->plus($quantity);
        }
        foreach($movementTotals as $movementId=>$quantity){
            $movement=DB::table('inventory_stock_movements')->where('id',$movementId)->first();
            if(!$movement || !$quantity->isPositive() ||
                $quantity->isGreaterThan((string)$movement->quantity))
                $this->fail('Original outbound stock movement cannot support the historical source FIFO issue quantities.');
            $all=DB::table('inventory_cost_consumptions')
                ->where('inventory_stock_movement_id',$movementId)->get(['quantity']);
            $total=BigDecimal::zero();
            foreach($all as $part)$total=$total->plus((string)$part->quantity);
            if(!$total->isEqualTo((string)$movement->quantity))
                $this->fail('Original physical issue has missing or excess FIFO consumption lines. Do not assume its historic cost or source location.');
        }
        $original=BigDecimal::of((string)$lot->original_quantity);
        $remaining=BigDecimal::of((string)$lot->remaining_quantity);
        if(!$original->isPositive() || $remaining->isNegative() ||
            !$original->isEqualTo($remaining->plus($used)))
            $this->fail('Original opening FIFO quantity does not reconcile to its remaining and genuinely consumed physical quantities.');
        $financial=DB::table('finance_opening_fifo_valuations')
            ->where('inventory_cost_layer_id',$lot->id)->first();
        if($financial){
            $journal=DB::table('journal_entries')
                ->where('id',$financial->journal_entry_id)->first();
            if(!$valued || !$journal || $journal->status!=='posted' ||
                $journal->reversal_of_id ||
                (int)$financial->currency_id!==(int)$lot->currency_id ||
                (int)$journal->currency_id!==(int)$lot->currency_id ||
                !BigDecimal::of((string)$financial->original_cost)
                    ->isEqualTo((string)$lot->original_cost) ||
                DB::table('journal_entries')->where('reversal_of_id',$journal->id)->exists())
                $this->fail('The previously approved original FIFO valuation GL is inconsistent or reversed. Location-only evidence cannot silently change its original source cost.');
        }

        $positions=DB::table('inventory_cost_layer_locations')
            ->where('inventory_cost_layer_id',$lot->id)
            ->orderBy('id')->get();
        $located=BigDecimal::zero();
        foreach($positions as $pos){
            $quantity=BigDecimal::of((string)$pos->quantity);
            if($quantity->isNegative() ||
                $pos->location_key!==($pos->company_warehouse_id===null
                    ?'unassigned':'wh:'.$pos->company_warehouse_id))
                $this->fail('The legacy FIFO lot has a negative or incorrectly keyed warehouse position.');
            $located=$located->plus($quantity);
        }
        if($located->isGreaterThan($remaining))
            $this->fail('Legacy FIFO positions already exceed actual remaining source lot packages.');

        $balances=DB::table('inventory_warehouse_balances')
            ->where('inventory_material_id',$material->id)
            ->orderBy('company_warehouse_id')->get();
        $fingerprintSource=[
            'original_opening'=>[$opening->id,$opening->inventory_material_id,
                $opening->type,$opening->direction,$opening->quantity,
                $opening->occurred_on,$opening->created_by,$opening->company_warehouse_id],
            'lot'=>[$lot->id,$lot->source_type,$lot->source_id,
                $lot->inventory_material_id,$lot->received_on->toDateString(),
                $lot->original_quantity,$lot->remaining_quantity,$lot->original_cost,
                $lot->currency_id,$lot->currency_code],
            'physical_material'=>[$material->id,$material->on_hand_quantity],
            'source_issues'=>$consumptions->toArray(),
            'locations'=>$positions->toArray(),
            'physical_warehouse_balances'=>$balances->toArray(),
        ];
        if($historicalRecognitions->isNotEmpty())
            $fingerprintSource['separately_posted_immutable_source_cost']=
                $historicalRecognitions->values()->toArray();
        if($productionRecognitions->isNotEmpty())
            $fingerprintSource['separately_posted_immutable_production_WIP_cost']=
                $productionRecognitions->values()->toArray();
        $fingerprint=hash('sha256',json_encode($fingerprintSource,
            JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'layer'=>$lot,'material'=>$material,'opening'=>$opening,
            'consumptions'=>$consumptions,'positions'=>$positions,
            'balances'=>$balances,'original'=>$original,'remaining'=>$remaining,
            'consumed'=>$used,'located'=>$located,
            'unpositioned'=>$remaining->minus($located),
            'fingerprint'=>$fingerprint,
        ];
    }

    private function qty(string $amount): BigDecimal
    {
        $value=BigDecimal::of($amount);
        if(!$value->isPositive() || $value->isGreaterThan('99999999999999.999999'))
            $this->fail('Choose an existing positive stock quantity in the source material precision.');
        return $value->toScale(6,RoundingMode::Unnecessary);
    }

    public function submit(int $layerId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($layerId,$data,$actorId){
            $first=InventoryCostLayer::findOrFail($layerId);
            InventoryMaterial::whereKey($first->inventory_material_id)
                ->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($layerId)->lockForUpdate()->firstOrFail();
            $source=$this->snapshot($layerId);
            if(DB::table('finance_legacy_fifo_source_reviews')
                ->where('inventory_cost_layer_id',$layerId)
                ->where('status','submitted')->exists())
                $this->fail('This original FIFO lot already has a pending independent physical/source review.');
            if((int)$source['opening']->created_by===$actorId)
                $this->fail('Original opening-stock receipt registrar cannot attest their own historical stock provenance.');
            $type=$data['review_type'];
            if(!in_array($type,['consumed_provenance','locate_remaining'],true))
                $this->fail('Unsupported opening stock provenance review type.');
            $warehouseId=null;$qty=null;
            if($type==='consumed_provenance'){
                if(DB::table('finance_legacy_fifo_source_reviews')
                    ->where('inventory_cost_layer_id',$layerId)
                    ->where('review_type','consumed_provenance')
                    ->where('status','approved')
                    ->where('source_fingerprint',$source['fingerprint'])->exists())
                    $this->fail('This unchanged original FIFO consumed-movement source has already received independent provenance approval. Do not duplicate documentary evidence.');
                if($source['layer']->original_cost!==null)
                    $this->fail('Only originally unvalued consumed FIFO packages need documentary source-cost provenance. Existing valued issues already have their immutable source costs.');
                if(!$source['consumed']->isPositive())
                    $this->fail('This FIFO layer has no documented historical consumption to review.');
                if(!empty($data['quantity']) || !empty($data['company_warehouse_id']))
                    $this->fail('A consumed-lot provenance review must not pretend to relocate consumed material.');
            }else{
                $warehouseId=(int)($data['company_warehouse_id']??0);
                if(!$warehouseId || !DB::table('company_warehouses')
                    ->where('id',$warehouseId)->where('is_active',true)->exists())
                    $this->fail('Select an active physically counted warehouse. Unassigned is not proof of an original stock location.');
                $qty=$this->qty((string)($data['quantity']??'0'));
                if($qty->isGreaterThan($source['remaining']) ||
                    $qty->isGreaterThan(
                        $source['unpositioned']->plus(
                            (string)($source['positions']->firstWhere('location_key','unassigned')->quantity??'0')
                        )
                    ))
                    $this->fail('Requested location attribution exceeds remaining original opening FIFO stock whose warehouse is genuinely unknown.');
                if($source['opening']->company_warehouse_id!==null &&
                    (int)$source['opening']->company_warehouse_id!==$warehouseId)
                    $this->fail('Original opening receipt names a different warehouse. A signed inter-warehouse transfer is needed; this procedure cannot invent one.');
            }
            $id=DB::table('finance_legacy_fifo_source_reviews')->insertGetId([
                'inventory_cost_layer_id'=>$layerId,
                'inventory_material_id'=>$source['material']->id,
                'review_type'=>$type,'status'=>'submitted',
                'company_warehouse_id'=>$warehouseId?:null,
                'quantity'=>$qty?(string)$qty:null,
                'opening_quantity'=>(string)$source['original']->toScale(6),
                'consumed_quantity'=>(string)$source['consumed']->toScale(6),
                'remaining_quantity'=>(string)$source['remaining']->toScale(6),
                'source_fingerprint'=>$source['fingerprint'],
                'source_evidence_reference'=>trim($data['source_evidence_reference']),
                'source_reconciliation_notes'=>trim($data['source_reconciliation_notes']),
                'requested_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('inventory.legacy_fifo_review.submitted',
                'finance_legacy_fifo_source_review',$id,
                'Original FIFO opening source/consumption and physical warehouse evidence submitted. No accounting, inventory or FIFO records changed.');
            return DB::table('finance_legacy_fifo_source_reviews')->where('id',$id)->first();
        });
    }

    private function locate(object $review,array $source,int $actorId): int
    {
        $layer=$source['layer'];
        $material=$source['material'];
        $warehouseId=(int)$review->company_warehouse_id;
        $quantity=$this->qty((string)$review->quantity);
        if(!DB::table('company_warehouses')->where('id',$warehouseId)
            ->where('is_active',true)->exists() ||
            ($source['opening']->company_warehouse_id!==null &&
                (int)$source['opening']->company_warehouse_id!==$warehouseId))
            $this->fail('Independent attribution needs an active physical warehouse consistent with the original opening receipt; undocumented historical transfers cannot be assumed.');
        if(!$source['remaining']->isPositive())
            $this->fail('A completely consumed opening FIFO lot has no existing quantity to attribute to a warehouse.');
        $balance=$source['balances']->firstWhere('company_warehouse_id',$warehouseId);
        $availablePhysical=BigDecimal::of((string)($balance->quantity??'0'));
        $allLocations=DB::table('inventory_cost_layer_locations as location')
            ->join('inventory_cost_layers as layer',
                'layer.id','=','location.inventory_cost_layer_id')
            ->where('layer.inventory_material_id',$material->id)
            ->where('location.company_warehouse_id',$warehouseId)
            ->lockForUpdate()->pluck('location.quantity');
        $assigned=BigDecimal::zero();
        foreach($allLocations as $value)$assigned=$assigned->plus((string)$value);
        if($availablePhysical->minus($assigned)->isLessThan($quantity))
            $this->fail('Selected warehouse has insufficient physically counted and not already source-attributed inventory.');
        if(BigDecimal::of((string)$material->on_hand_quantity)
            ->isLessThan($source['remaining']))
            $this->fail('Existing global material on-hand is lower than the original remaining FIFO lot. Repair physical stock source before attribution.');

        $unassigned=$source['positions']->firstWhere('location_key','unassigned');
        $pool=BigDecimal::of((string)($unassigned->quantity??'0'));
        $missing=$source['unpositioned'];
        if($pool->plus($missing)->isLessThan($quantity))
            $this->fail('The requested quantity is not supported by an unknown/unattributed remaining FIFO source location.');
        $fromPool=$pool->isLessThan($quantity)?$pool:$quantity;
        if($fromPool->isPositive()){
            DB::table('inventory_cost_layer_locations')
                ->where('id',$unassigned->id)->update([
                    'quantity'=>(string)$pool->minus($fromPool)->toScale(6),
                    'updated_at'=>now(),
                ]);
        }
        $current=$source['positions']->firstWhere('location_key','wh:'.$warehouseId);
        if($current){
            DB::table('inventory_cost_layer_locations')->where('id',$current->id)
                ->update([
                    'quantity'=>(string)BigDecimal::of((string)$current->quantity)
                        ->plus($quantity)->toScale(6),
                    'updated_at'=>now(),
                ]);
        }else{
            DB::table('inventory_cost_layer_locations')->insert([
                'inventory_cost_layer_id'=>$layer->id,
                'company_warehouse_id'=>$warehouseId,
                'location_key'=>'wh:'.$warehouseId,
                'quantity'=>(string)$quantity,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        return DB::table('inventory_legacy_lot_assignments')->insertGetId([
            'inventory_cost_layer_id'=>$layer->id,
            'company_warehouse_id'=>$warehouseId,
            'quantity'=>(string)$quantity,
            'reference'=>$review->source_evidence_reference,
            'reason'=>$review->source_reconciliation_notes.' | Independent finance approval '.
                $review->decision_reference,
            'assigned_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function review(int $reviewId,array $decision,int $actorId): object
    {
        return DB::transaction(function()use($reviewId,$decision,$actorId){
            $initial=DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$reviewId)->first();
            abort_unless($initial,404);
            InventoryMaterial::whereKey($initial->inventory_material_id)
                ->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($initial->inventory_cost_layer_id)
                ->lockForUpdate()->firstOrFail();
            $review=DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$reviewId)->lockForUpdate()->first();
            if($review->status!=='submitted' ||
                !in_array($review->review_type,['consumed_provenance','locate_remaining'],true))
                $this->fail('Only a pending recognized original FIFO provenance/warehouse review can receive an independent decision.');
            $source=$this->snapshot((int)$review->inventory_cost_layer_id);
            if(!hash_equals((string)$review->source_fingerprint,$source['fingerprint']) ||
                !BigDecimal::of((string)$review->opening_quantity)->isEqualTo($source['original']) ||
                !BigDecimal::of((string)$review->consumed_quantity)->isEqualTo($source['consumed']) ||
                !BigDecimal::of((string)$review->remaining_quantity)->isEqualTo($source['remaining']))
                $this->fail('Original opening movement, FIFO issues, physical quantity or warehouse count changed after source request. Reject the stale review and submit a fresh one.');
            if(in_array($actorId,[
                (int)$review->requested_by,(int)$source['opening']->created_by,
            ],true))
                $this->fail('Independent reviewer must differ from the original opening-stock registrar and evidence proposer.');
            if($review->review_type==='consumed_provenance'){
                foreach($source['consumptions'] as $historicIssue){
                    if((int)$historicIssue->created_by===$actorId ||
                        (int)$historicIssue->created_by===(int)$review->requested_by)
                        $this->fail('Independent consumed-opening FIFO source evidence requester/reviewer cannot be the original adjustment or production issue author.');
                }
            }
            $approved=$decision['decision']==='approve';
            DB::table('finance_legacy_fifo_source_reviews')->where('id',$reviewId)
                ->update([
                    'status'=>$approved?'approved':'rejected',
                    'decision_reference'=>trim($decision['decision_reference']),
                    'decision_notes'=>trim($decision['decision_notes']),
                    'reviewed_by'=>$actorId,'reviewed_at'=>now(),'updated_at'=>now(),
                ]);
            $assignmentId=null;
            if($approved && $review->review_type==='locate_remaining'){
                $signed=DB::table('finance_legacy_fifo_source_reviews')
                    ->where('id',$reviewId)->first();
                $assignmentId=$this->locate($signed,$source,$actorId);
                DB::table('finance_legacy_fifo_source_reviews')
                    ->where('id',$reviewId)->update([
                        'inventory_legacy_lot_assignment_id'=>$assignmentId,
                        'updated_at'=>now(),
                    ]);
            }
            ActivityLog::record('inventory.legacy_fifo_review.'.
                ($approved?'approved':'rejected'),
                'finance_legacy_fifo_source_review',$reviewId,
                $approved && $review->review_type==='locate_remaining'
                    ?'Independently verified source FIFO remaining lot attributed to physically counted existing warehouse, without stock or GL changes.'
                    :'Historical opening FIFO consumed provenance reviewed without changing immutable source costs, stock, issues or GL.',
                ['layer_id'=>$review->inventory_cost_layer_id,
                    'assignment_id'=>$assignmentId,
                    'consumed_quantity'=>$review->consumed_quantity,
                    'remaining_quantity'=>$review->remaining_quantity]);
            return DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$reviewId)->first();
        });
    }
}
