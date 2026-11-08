<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use App\Models\InventoryStockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryCostService
{
    public function valuationRows(?string $currencyCode = null): array
    {
        $rows = [];
        $trackedQuantities = [];
        $layers = InventoryCostLayer::with('material')->where('remaining_quantity', '>', 0)->orderBy('inventory_material_id')->orderBy('received_on')->get();
        foreach ($layers as $layer) {
            $code = $layer->currency_code;
            if ($currencyCode && $code !== $currencyCode) continue;
            $valued = $layer->original_cost !== null && $layer->currency_id !== null;
            $key = $layer->inventory_material_id.'|'.($code ?? 'UNVALUED').'|'.($valued ? 'valued' : 'unvalued');
            $rows[$key] ??= [
                'material' => $layer->material,
                'currency_code' => $valued ? $code : null,
                'quantity' => BigDecimal::zero(),
                'value' => BigDecimal::zero(),
                'is_valued' => $valued,
            ];
            $rows[$key]['quantity'] = $rows[$key]['quantity']->plus((string) $layer->remaining_quantity);
            $trackedQuantities[$layer->inventory_material_id] = ($trackedQuantities[$layer->inventory_material_id] ?? BigDecimal::zero())
                ->plus((string) $layer->remaining_quantity);
            if ($valued) $rows[$key]['value'] = $rows[$key]['value']->plus($this->remainingLayerCost($layer));
        }

        if ($currencyCode === null) {
            foreach (InventoryMaterial::query()->where('on_hand_quantity', '>', 0)->get() as $material) {
                $physical = BigDecimal::of((string) $material->on_hand_quantity);
                $tracked = $trackedQuantities[$material->id] ?? BigDecimal::zero();
                $untracked = $physical->minus($tracked);
                if (! $untracked->isGreaterThan(0)) continue;
                $key = $material->id.'|UNVALUED|unvalued';
                $rows[$key] ??= ['material' => $material, 'currency_code' => null, 'quantity' => BigDecimal::zero(), 'value' => BigDecimal::zero(), 'is_valued' => false];
                $rows[$key]['quantity'] = $rows[$key]['quantity']->plus($untracked);
            }
        }

        return array_values(array_map(fn (array $row) => [
            'material' => $row['material'],
            'currency_code' => $row['currency_code'],
            'quantity' => (string) $row['quantity']->toScale(6),
            'value' => $row['is_valued'] ? (string) $row['value']->toScale(10, RoundingMode::HalfUp) : null,
            'quantity_display' => $this->formatDecimal((string) $row['quantity']->toScale(6)),
            'value_display' => $row['is_valued'] ? $this->formatDecimal((string) $row['value']->toScale(4, RoundingMode::HalfUp)) : null,
            'is_valued' => $row['is_valued'],
        ], $rows));
    }

    /**
     * Present each remaining lot by its verified warehouse position.
     * A missing location receives its own unreconciled row, never a
     * guessed warehouse. Cost totals are apportioned within each source
     * currency and retain all ten source decimal places.
     */
    public function locationValuationRows(Collection $layers): array
    {
        if($layers->isEmpty())return [];
        $positions=DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))->get()
            ->groupBy('inventory_cost_layer_id');
        $warehouses=\App\Models\CompanyWarehouse::whereIn('id',
            $positions->flatten()->pluck('company_warehouse_id')->filter()->unique()
        )->get()->keyBy('id');
        $rows=[];
        foreach($layers as $layer){
            $remaining=BigDecimal::of((string)$layer->remaining_quantity);
            if(!$remaining->isPositive())continue;
            $locations=[];$totalPositioned=BigDecimal::zero();
            foreach($positions->get($layer->id,collect()) as $position){
                $qty=BigDecimal::of((string)$position->quantity);
                if(!$qty->isPositive())continue;
                $totalPositioned=$totalPositioned->plus($qty);
                $locations[]=[
                    'location'=>$position->company_warehouse_id===null
                        ?'Unassigned pool'
                        :($warehouses->get($position->company_warehouse_id)?->name??'Unverified warehouse'),
                    'quantity'=>$qty,'status'=>'Located',
                ];
            }
            $misplaced=$totalPositioned->isGreaterThan($remaining);
            $unlocated=$remaining->minus($totalPositioned);
            if($unlocated->isPositive()){
                $locations[]=[
                    'location'=>'Location not reconciled',
                    'quantity'=>$unlocated,'status'=>'Reconciliation required',
                ];
            }
            if($locations===[]){
                $locations[]=['location'=>'Location not reconciled',
                    'quantity'=>$remaining,'status'=>'Reconciliation required'];
            }

            $valued=$layer->original_cost!==null && $layer->currency_id!==null &&
                $layer->currency_code!==null;
            $cost=$valued?$this->remainingLayerCost($layer):null;
            $distributed=BigDecimal::zero();
            foreach($locations as $index=>$location){
                $last=$index===count($locations)-1;
                $value=null;
                if($valued && !$misplaced){
                    $value=$last
                        ?$cost->minus($distributed)
                        :$cost->multipliedBy($location['quantity'])
                            ->dividedBy($remaining,10,RoundingMode::HalfUp);
                    $distributed=$distributed->plus($value);
                }
                $rows[]=[
                    'layer_id'=>$layer->id,
                    'material'=>$layer->material,
                    'source'=>$layer->source_type.' #'.$layer->source_id,
                    'received_on'=>$layer->received_on?->toDateString(),
                    'location'=>$location['location'],
                    'quantity'=>(string)$location['quantity']->toScale(6),
                    'currency_code'=>$layer->currency_code,
                    'value'=>$value===null?null:(string)$value->toScale(10,RoundingMode::HalfUp),
                    'status'=>$misplaced?'Location mismatch':
                        (!$valued?'Unvalued':$location['status']),
                ];
            }
        }
        return $rows;
    }

    public function receiveGoodsReceiptLine(int $lineId, int $actorId): InventoryCostLayer
    {
        $line = GoodsReceiptLine::with('receipt')->findOrFail($lineId);
        $receipt = $line->receipt;

        return $this->createLayer(
            (int) $line->inventory_material_id,
            'goods_receipt_line',
            $line->id,
            (string) $line->quantity,
            $line->line_subtotal_snapshot === null ? null : (string) $line->line_subtotal_snapshot,
            $receipt?->cost_currency_id,
            $receipt?->cost_currency_code,
            $receipt?->received_on?->toDateString() ?? now()->toDateString(),
            $actorId,
            $receipt?->company_warehouse_id,
        );
    }

    public function createFromInboundMovement(InventoryStockMovement $movement, ?string $unitCost, ?int $currencyId, ?int $actorId): InventoryCostLayer
    {
        $totalCost = null;
        $code = null;
        if ($unitCost !== null && $currencyId !== null) {
            $currency = Currency::whereKey($currencyId)->where('is_active', true)->firstOrFail();
            $totalCost = BigDecimal::of((string) $unitCost)->multipliedBy((string) $movement->quantity)->toScale(10, RoundingMode::HalfUp);
            $code = $currency->code;
        }

        return $this->createLayer(
            (int) $movement->inventory_material_id,
            'inventory_stock_movement',
            $movement->id,
            (string) $movement->quantity,
            $totalCost === null ? null : (string) $totalCost,
            $currencyId,
            $code,
            $movement->occurred_on->toDateString(),
            $actorId,
            $movement->company_warehouse_id,
        );
    }

    public function consume(InventoryStockMovement $movement): void
    {
        if (InventoryCostConsumption::where('inventory_stock_movement_id', $movement->id)->exists()) return;
        $locations = app(InventoryLotLocationService::class);
        $warehouseId = $movement->company_warehouse_id === null
            ? null : (int)$movement->company_warehouse_id;

        if ($movement->reference_type === 'purchase_return_line') {
            $line = \App\Models\PurchaseReturnRecordLine::findOrFail($movement->reference_id);
            $origin = InventoryCostLayer::where('source_type','goods_receipt_line')
                ->where('source_id',$line->goods_receipt_line_id)->lockForUpdate()->first();
            if (!$origin) {
                throw ValidationException::withMessages([
                    'quantity'=>'The source goods receipt lot is missing its FIFO cost layer. Reconcile the receipt before returning physical stock.',
                ]);
            }
            if($movement->occurred_on->toDateString()<$origin->received_on->toDateString()){
                throw ValidationException::withMessages([
                    'occurred_on'=>'A supplier return cannot predate its original receipt FIFO lot.',
                ]);
            }
            $remaining = BigDecimal::of((string)$origin->remaining_quantity);
            $returnQty = BigDecimal::of((string)$movement->quantity);
            if ($remaining->isLessThan($returnQty)) {
                throw ValidationException::withMessages([
                    'quantity'=>'The source receipt lot no longer contains enough stock for this supplier return.',
                ]);
            }
            $locations->consume($origin,$warehouseId,$returnQty);
            InventoryCostConsumption::create([
                'inventory_stock_movement_id'=>$movement->id,
                'inventory_cost_layer_id'=>$origin->id,
                'quantity'=>(string)$returnQty->toScale(6),
                'amount'=>$this->amountForConsumption($origin,$returnQty),
                'currency_id'=>$origin->currency_id,'currency_code'=>$origin->currency_code,
                'is_valued'=>$origin->original_cost!==null && $origin->currency_id!==null,
            ]);
            $origin->update(['remaining_quantity'=>(string)$remaining->minus($returnQty)->toScale(6)]);
            return;
        }

        $needed = BigDecimal::of((string)$movement->quantity);
        $layers = InventoryCostLayer::where('inventory_material_id',$movement->inventory_material_id)
            ->where('remaining_quantity','>',0)
            ->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
        $positionRows = DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))
            ->lockForUpdate()->get()->groupBy('inventory_cost_layer_id');

        foreach($layers as $layer){
            $positions=$positionRows->get($layer->id,collect());
            $located=BigDecimal::zero();
            foreach($positions as $position)$located=$located->plus((string)$position->quantity);
            // Historical layers must be physically reconciled before FIFO may
            // skip them in favour of newer, positioned receipts.
            if($located->isLessThan((string)$layer->remaining_quantity)){
                throw ValidationException::withMessages([
                    'quantity'=>'Material has historical FIFO lots with unknown locations. Reconcile remaining lot locations before issuing or adjusting stock.',
                ]);
            }
            if($located->isGreaterThan((string)$layer->remaining_quantity)){
                throw ValidationException::withMessages([
                    'quantity'=>'FIFO lot positions exceed their remaining material quantity. Reconcile lot accounting before posting.',
                ]);
            }
        }
        foreach($layers as $layer){
            if(!$needed->isPositive())break;
            if(app(QualityMaterialHoldService::class)->isLayerHeld($layer))continue;
            $position=$positionRows->get($layer->id,collect())
                ->firstWhere('location_key',$warehouseId===null?'unassigned':'wh:'.$warehouseId);
            $available=BigDecimal::of((string)($position->quantity??'0'));
            if(!$available->isPositive())continue;
            $layerRemaining=BigDecimal::of((string)$layer->remaining_quantity);
            $qty=$available->isLessThan($needed)?$available:$needed;
            if($layerRemaining->isLessThan($qty))$qty=$layerRemaining;
            if(!$qty->isPositive())continue;
            if($movement->occurred_on->toDateString()<$layer->received_on->toDateString()){
                throw ValidationException::withMessages([
                    'occurred_on'=>'An inventory issue cannot consume a FIFO lot received after the issue date.',
                ]);
            }

            $amount=$this->amountForConsumption($layer,$qty);
            $locations->consume($layer,$warehouseId,$qty);
            InventoryCostConsumption::create([
                'inventory_stock_movement_id'=>$movement->id,
                'inventory_cost_layer_id'=>$layer->id,
                'quantity'=>(string)$qty->toScale(6),
                'amount'=>$amount,'currency_id'=>$layer->currency_id,
                'currency_code'=>$layer->currency_code,
                'is_valued'=>$layer->original_cost!==null && $layer->currency_id!==null,
            ]);
            $layer->update(['remaining_quantity'=>(string)$layerRemaining->minus($qty)->toScale(6)]);
            $needed=$needed->minus($qty);
        }
        if($needed->isPositive()){
            throw ValidationException::withMessages([
                'quantity'=>'Insufficient physically attributed, quality-released FIFO stock at the selected warehouse or unassigned pool. No quantity or cost was posted.',
            ]);
        }
    }

    public function assertLandedCostAllowed(int $lineId): void
    {
        $layer = InventoryCostLayer::where(['source_type' => 'goods_receipt_line', 'source_id' => $lineId])->lockForUpdate()->first();
        if (! $layer) throw ValidationException::withMessages(['receipt' => 'This receipt line has no valuation layer; landed cost cannot be applied safely.']);
        if (InventoryCostConsumption::where('inventory_cost_layer_id', $layer->id)->exists()) {
            throw ValidationException::withMessages(['receipt' => 'Landed costs must be recorded before this material is issued.']);
        }
        if ($layer->currency_code === null) {
            throw ValidationException::withMessages(['receipt' => 'This receipt has no cost-currency snapshot.']);
        }
    }

    private function createLayer(int $materialId, string $sourceType, int $sourceId, string $quantity, ?string $cost, ?int $currencyId, ?string $currencyCode, string $date, ?int $actorId, ?int $warehouseId): InventoryCostLayer
    {
        $layer = InventoryCostLayer::firstOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            [
                'inventory_material_id' => $materialId,
                'original_quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'original_cost' => $cost,
                'currency_id' => $currencyId,
                'currency_code' => $currencyCode,
                'received_on' => $date,
                'created_by' => $actorId,
            ],
        );
        if($layer->wasRecentlyCreated){
            app(InventoryLotLocationService::class)->recordNew($layer,$warehouseId);
        }
        // Existing layers were not retroactively assigned to warehouses:
        // their historic movements need source-ledger reconciliation.
        return $layer;
    }

    private function amountForConsumption(InventoryCostLayer $layer, BigDecimal $quantity): ?string
    {
        if ($layer->original_cost === null || $layer->currency_id === null) return null;
        $availableCost = $this->remainingLayerCost($layer);
        $remaining = BigDecimal::of((string) $layer->remaining_quantity);
        if ($quantity->compareTo($remaining) === 0) return (string) $availableCost->toScale(10, RoundingMode::HalfUp);
        return (string) $availableCost->multipliedBy($quantity)->dividedBy((string) $remaining, 10, RoundingMode::HalfUp)->toScale(10, RoundingMode::HalfUp);
    }

    private function remainingLayerCost(InventoryCostLayer $layer): BigDecimal
    {
        $landed = $layer->source_type === 'goods_receipt_line'
            ? DB::table('goods_receipt_landed_cost_allocations')->where('goods_receipt_line_id', $layer->source_id)->sum('amount')
            : '0';
        $totalCost = BigDecimal::of((string) $layer->original_cost)->plus((string) $landed);
        $used = BigDecimal::zero();
        foreach ($layer->consumptions()->where('is_valued', true)->pluck('amount') as $prior) $used = $used->plus((string) $prior);
        return $totalCost->minus($used);
    }

    private function formatDecimal(string $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);
        $fraction = rtrim($fraction, '0');
        return $integer.($fraction === '' ? '' : '.'.$fraction);
    }
}
