<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductionMaterialWaste;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source-cost attribution for abnormal production loss. Physical stock was
 * already issued: this service never adjusts stock or posts a separate journal.
 * ManufacturingCostAccountingService later debits abnormal loss expense
 * and excludes the exact same value from finished-goods capitalization.
 */
class ProductionAbnormalWasteCostService
{
    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['abnormal_waste'=>$message]);
    }

    public function candidates(int $materialLineId):Collection
    {
        return DB::table('inventory_cost_consumptions as cost')
            ->join('inventory_stock_movements as movement',
                'movement.id','=','cost.inventory_stock_movement_id')
            ->leftJoin('inventory_cost_layers as layer',
                'layer.id','=','cost.inventory_cost_layer_id')
            ->where('movement.reference_type','production_order_material')
            ->where('movement.reference_id',$materialLineId)
            ->orderBy('cost.id')
            ->get(['cost.id','cost.inventory_cost_layer_id',
                'cost.quantity','cost.amount','cost.currency_id','cost.currency_code',
                'cost.is_valued','layer.source_type','layer.source_id']);
    }

    public function approve(int $wasteId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($wasteId,$data,$actorId){
            $waste=ProductionMaterialWaste::whereKey($wasteId)
                ->lockForUpdate()->firstOrFail();
            $classification=DB::table('production_waste_financial_reviews')
                ->where('production_material_waste_id',$wasteId)
                ->lockForUpdate()->first();
            if(!$classification || $classification->treatment!=='abnormal_loss'){
                $this->reject('Only independently classified abnormal production waste can receive an exact FIFO expense attribution.');
            }
            if((int)$waste->recorded_by===$actorId ||
                (int)$classification->reviewed_by===$actorId){
                $this->reject('A third independent reviewer must approve abnormal-loss FIFO expense attribution.');
            }
            if(DB::table('production_abnormal_waste_cost_reviews')
                ->where('production_material_waste_id',$wasteId)->exists()){
                $this->reject('This waste event already has a frozen source-cost decision. Reconcile through a controlled correction, not a second posting.');
            }
            $line=$waste->materialLine()->lockForUpdate()->firstOrFail();
            $order=DB::table('production_orders')->where('id',$line->production_order_id)
                ->lockForUpdate()->first();
            if(!$order || !in_array($order->status,['issued','pending_qc','qc_approved'],true)){
                $this->reject('Review the waste source before physical warehouse release or completed production.');
            }
            $batch=DB::table('production_batches')
                ->where('production_order_id',$order->id)->first();
            if($batch && (
                in_array($batch->status,['released','qc_rejected'],true) ||
                DB::table('production_batch_cost_postings')
                    ->where('production_batch_id',$batch->id)->exists() ||
                DB::table('finished_good_dispatch_lines')
                    ->where('production_batch_id',$batch->id)->exists())){
                $this->reject('Waste cost cannot be assigned after batch release, rejection, capitalization or sale.');
            }
            $allocations=$data['allocations']??[];
            if(!is_array($allocations) || !$allocations){
                $this->reject('Assign the abnormal material quantity to one or more exact issued FIFO consumption records.');
            }
            $available=$this->candidates((int)$line->id)->keyBy('id');
            $positive=[];
            foreach($allocations as $id=>$quantity){
                $qty=BigDecimal::of((string)$quantity);
                if($qty->isNegative())$this->reject('Abnormal source quantities cannot be negative.');
                if($qty->isPositive())$positive[(int)$id]=$qty;
            }
            if(!$positive)$this->reject('Select positive quantities from the exact source FIFO issues.');
            $totalQty=BigDecimal::zero();
            $totalCost=BigDecimal::zero();
            $currencyId=null;
            $parts=[];
            foreach($positive as $id=>$qty){
                $row=$available->get($id);
                if(!$row || !$row->inventory_cost_layer_id || !$row->is_valued ||
                    $row->amount===null || $row->currency_id===null ||
                    $row->source_type!=='goods_receipt_line'){
                    $this->reject('A selected waste allocation has no verified original-cost GRN FIFO consumption.');
                }
                $consumed=BigDecimal::of((string)$row->quantity);
                $sourceCost=BigDecimal::of((string)$row->amount);
                if(!$consumed->isPositive() || !$sourceCost->isPositive()){
                    $this->reject('Waste source FIFO quantity and value must both be positive.');
                }
                if($currencyId===null)$currencyId=(int)$row->currency_id;
                if($currencyId!==(int)$row->currency_id){
                    $this->reject('Abnormal material expense cannot combine FIFO consumptions in different currencies.');
                }
                $priorQty=BigDecimal::zero();$priorCost=BigDecimal::zero();
                $used=DB::table('production_abnormal_waste_cost_allocations')
                    ->where('inventory_cost_consumption_id',$id)->lockForUpdate()
                    ->get(['quantity','source_cost_amount']);
                foreach($used as $record){
                    $priorQty=$priorQty->plus((string)$record->quantity);
                    $priorCost=$priorCost->plus((string)$record->source_cost_amount);
                }
                $freeQty=$consumed->minus($priorQty);
                if($qty->isGreaterThan($freeQty) || !$freeQty->isPositive()){
                    $this->reject('Abnormal allocations exceed the unused quantity of a recorded source FIFO issue.');
                }
                $freeCost=$sourceCost->minus($priorCost);
                $value=$qty->isEqualTo($freeQty)
                    ?$freeCost
                    :$sourceCost->multipliedBy($qty)
                        ->dividedBy($consumed,10,RoundingMode::HalfUp);
                if(!$value->isPositive() || $value->isGreaterThan($freeCost)){
                    $this->reject('The selected FIFO consumption cannot support this loss cost without duplicating an earlier allocation.');
                }
                $totalQty=$totalQty->plus($qty);
                $totalCost=$totalCost->plus($value);
                $parts[]=['inventory_cost_consumption_id'=>$id,
                    'quantity'=>(string)$qty->toScale(6),
                    'source_cost_amount'=>(string)$value->toScale(10)];
            }
            if(!$totalQty->isEqualTo((string)$waste->quantity)){
                $this->reject('Selected lot quantities must total exactly the immutable recorded waste quantity.');
            }
            if($currencyId===null || !$totalCost->isPositive() ||
               $totalCost->toScale(4,RoundingMode::HalfUp)->isZero()){
                $this->reject('Source value cannot be represented as a positive original-currency GL expense.');
            }
            $policy=app(ManufacturingLedgerPolicyService::class)->approved($currencyId);
            $ledger=DB::table('ledger_accounts')
                ->where('id',$data['expense_ledger_id'])->first();
            if(!$ledger || !$ledger->is_active || $ledger->is_group ||
                $ledger->type!=='expense' ||
                (int)$ledger->id===(int)$policy->cogs_ledger_id ||
                DB::table('entity_ledger_accounts')
                    ->where('ledger_account_id',$ledger->id)->exists()){
                $this->reject('Choose a distinct active abnormal production loss expense ledger, not COGS or a mapped party account.');
            }
            if($batch && DB::table('production_overhead_allocations as overhead')
                ->join('journal_lines as source',
                    'source.id','=','overhead.source_journal_line_id')
                ->where('overhead.production_batch_id',$batch->id)
                ->whereIn('overhead.status',['submitted','approved'])
                ->where('source.ledger_account_id',$ledger->id)->exists()){
                $this->reject('Abnormal loss expense must be independent from any source overhead expense ledger allocated to the same production batch.');
            }
            if(DB::table('production_abnormal_waste_cost_reviews')
                ->where('production_material_waste_id',$wasteId)->exists()){
                $this->reject('Concurrent abnormal-loss allocation already exists.');
            }
            $reviewId=DB::table('production_abnormal_waste_cost_reviews')->insertGetId([
                'production_material_waste_id'=>$wasteId,
                'currency_id'=>$currencyId,'expense_ledger_id'=>$ledger->id,
                'source_cost_amount'=>(string)$totalCost->toScale(10),
                'approval_reference'=>trim($data['approval_reference']),
                'source_evidence'=>trim($data['source_evidence']),
                'approved_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($parts as $part){
                DB::table('production_abnormal_waste_cost_allocations')->insert([
                    'production_abnormal_waste_cost_review_id'=>$reviewId,
                    ...$part,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            ActivityLog::record('manufacturing.abnormal_waste.cost_approved',
                'production_material_waste',$wasteId,
                'Exact original FIFO consumption cost independently assigned to abnormal material loss; journal deferred to batch capitalization.',[
                    'review_id'=>$reviewId,'currency_id'=>$currencyId,
                    'amount'=>(string)$totalCost->toScale(10),
                    'expense_ledger_id'=>$ledger->id,
                ]);
            return DB::table('production_abnormal_waste_cost_reviews')
                ->where('id',$reviewId)->first();
        });
    }

    /**
     * Validate every abnormal-loss record before inventory release or WIP
     * posting. Returns grouped expense debits for the batch's one currency.
     * No unsupported historical source is silently priced.
     */
    public function forOrder(int $orderId,?int $expectedCurrencyId=null,?string $asOf=null):array
    {
        $records=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as line',
                'line.id','=','waste.production_order_material_id')
            ->join('production_waste_financial_reviews as classification',
                'classification.production_material_waste_id','=','waste.id')
            ->leftJoin('production_abnormal_waste_cost_reviews as cost',
                'cost.production_material_waste_id','=','waste.id')
            ->where('line.production_order_id',$orderId)
            ->where('classification.treatment','abnormal_loss')
            ->select('waste.id','waste.quantity','cost.id as review_id',
                'cost.currency_id','cost.expense_ledger_id',
                'cost.source_cost_amount','cost.created_at as approved_at')
            ->get();
        $result=[];$total=BigDecimal::zero();
        foreach($records as $record){
            if(!$record->review_id){
                $this->reject('Classified abnormal production loss has no reviewed FIFO lot-cost allocation.');
            }
            if($expectedCurrencyId!==null &&
                (int)$record->currency_id!==$expectedCurrencyId){
                $this->reject('Abnormal waste source cost currency differs from the batch WIP currency.');
            }
            if($asOf!==null && substr((string)$record->approved_at,0,10)>$asOf){
                $this->reject('Abnormal waste finance approval postdates the proposed manufacturing journal date.');
            }
            $parts=DB::table('production_abnormal_waste_cost_allocations')
                ->where('production_abnormal_waste_cost_review_id',$record->review_id)->get();
            $qty=BigDecimal::zero();$value=BigDecimal::zero();
            foreach($parts as $part){
                $source=DB::table('inventory_cost_consumptions')
                    ->where('id',$part->inventory_cost_consumption_id)->first();
                if(!$source || !$source->is_valued ||
                    (int)$source->currency_id!==(int)$record->currency_id){
                    $this->reject('An approved abnormal waste lot source is missing or has invalid original currency.');
                }
                $qty=$qty->plus((string)$part->quantity);
                $value=$value->plus((string)$part->source_cost_amount);
            }
            if(!$qty->isEqualTo((string)$record->quantity) ||
                !$value->isEqualTo((string)$record->source_cost_amount)){
                $this->reject('Approved abnormal waste cost allocation has a source quantity or total value mismatch.');
            }
            $account=(int)$record->expense_ledger_id;
            $result[$account]=($result[$account]??BigDecimal::zero())
                ->plus((string)$record->source_cost_amount);
            $total=$total->plus((string)$record->source_cost_amount);
        }
        return ['total'=>$total,'by_ledger'=>$result,'waste_count'=>$records->count()];
    }
}
