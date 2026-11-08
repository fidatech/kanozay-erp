<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CompanyWarehouse;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material location attribution is separate from the immutable FIFO valuation
 * layer. Transfers move attributable quantities, never create new stock or cost.
 * All mutating methods must run under the inventory material row lock.
 */
class InventoryLotLocationService
{
    private function key(?int $warehouseId): string
    {
        return $warehouseId === null ? 'unassigned' : 'wh:'.$warehouseId;
    }

    private function amount(string $value): BigDecimal
    {
        $quantity = BigDecimal::of($value);
        if (! $quantity->isPositive() || $quantity->isGreaterThan('99999999999999.999999')) {
            throw ValidationException::withMessages(['quantity' => 'Enter a positive quantity in the supported range.']);
        }
        return $quantity->toScale(6, \Brick\Math\RoundingMode::Unnecessary);
    }

    /**
     * Only called for a newly created cost layer, never for an older layer
     * whose true warehouse history may differ from the source receipt.
     */
    public function recordNew(InventoryCostLayer $layer, ?int $warehouseId): void
    {
        DB::table('inventory_cost_layer_locations')->insert([
            'inventory_cost_layer_id' => $layer->id,
            'company_warehouse_id' => $warehouseId,
            'location_key' => $this->key($warehouseId),
            'quantity' => (string) BigDecimal::of((string)$layer->remaining_quantity)->toScale(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Consume a quantity of this specific lot at the actual stock movement
     * location. A legacy lot with unknown provenance cannot be silently used.
     */
    public function consume(InventoryCostLayer $layer, ?int $warehouseId, BigDecimal $quantity): void
    {
        $key = $this->key($warehouseId);
        $position = DB::table('inventory_cost_layer_locations')
            ->where('inventory_cost_layer_id', $layer->id)
            ->where('location_key', $key)->lockForUpdate()->first();
        if (! $position || BigDecimal::of((string)$position->quantity)->isLessThan($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => 'Lot '.$layer->id.' has insufficient verified stock at this location. Reconcile legacy lot positions first.',
            ]);
        }
        $after = BigDecimal::of((string)$position->quantity)->minus($quantity);
        DB::table('inventory_cost_layer_locations')->where('id', $position->id)
            ->update(['quantity' => (string)$after->toScale(6), 'updated_at' => now()]);
    }

    /**
     * FIFO movement of only quality-released lots out of a physical location.
     * A warehouse movement has one immutable row per contributing FIFO layer.
     */
    public function move(
        InventoryMaterial $material,
        ?int $fromId,
        int $toId,
        BigDecimal $quantity,
        int $warehouseMovementId,
        string $movementDate
    ): void {
        $needed = $quantity;
        $layers = InventoryCostLayer::where('inventory_material_id', $material->id)
            ->where('remaining_quantity','>',0)
            ->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();

        $positions = DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))
            ->where('location_key',$this->key($fromId))
            ->lockForUpdate()->get()->keyBy('inventory_cost_layer_id');
        // Unknown historical positions cannot be bypassed when selecting
        // newer lots for FIFO transfers.
        $allPositions = DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))
            ->lockForUpdate()->get()->groupBy('inventory_cost_layer_id');
        foreach($layers as $layer){
            $sum=BigDecimal::zero();
            foreach($allPositions->get($layer->id,collect()) as $position){
                $sum=$sum->plus((string)$position->quantity);
            }
            if(!$sum->isEqualTo((string)$layer->remaining_quantity)){
                throw ValidationException::withMessages([
                    'quantity'=>'Unreconciled lot locations or excess positions detected. Reconcile historical cost layers before warehouse transfers.',
                ]);
            }
        }
        $changes = [];
        foreach ($layers as $layer) {
            if (! $needed->isPositive()) break;
            if (app(QualityMaterialHoldService::class)->isLayerHeld($layer)) continue;
            $position = $positions->get($layer->id);
            if (! $position) continue;
            $atSource = BigDecimal::of((string)$position->quantity);
            if (! $atSource->isPositive()) continue;
            $take = $atSource->isLessThan($needed) ? $atSource : $needed;
            if($movementDate < $layer->received_on->toDateString()){
                throw ValidationException::withMessages([
                    'occurred_on'=>'A transfer cannot be dated before the original FIFO lot was received.',
                ]);
            }
            $changes[] = [$layer,$position,$take];
            $needed = $needed->minus($take);
        }
        if ($needed->isPositive()) {
            throw ValidationException::withMessages([
                'quantity' => 'Insufficient quality-released FIFO lots with verified source locations. Held or legacy unassigned lots cannot be moved without reconciliation.',
            ]);
        }

        foreach ($changes as [$layer,$position,$take]) {
            $sourceLeft = BigDecimal::of((string)$position->quantity)->minus($take);
            DB::table('inventory_cost_layer_locations')->where('id',$position->id)
                ->update(['quantity'=>(string)$sourceLeft->toScale(6),'updated_at'=>now()]);
            $target = DB::table('inventory_cost_layer_locations')
                ->where('inventory_cost_layer_id',$layer->id)
                ->where('location_key',$this->key($toId))->lockForUpdate()->first();
            if ($target) {
                $next = BigDecimal::of((string)$target->quantity)->plus($take);
                DB::table('inventory_cost_layer_locations')->where('id',$target->id)
                    ->update(['quantity'=>(string)$next->toScale(6),'updated_at'=>now()]);
            } else {
                DB::table('inventory_cost_layer_locations')->insert([
                    'inventory_cost_layer_id'=>$layer->id,'company_warehouse_id'=>$toId,
                    'location_key'=>$this->key($toId),'quantity'=>(string)$take->toScale(6),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('inventory_warehouse_lot_movements')->insert([
                'inventory_warehouse_movement_id'=>$warehouseMovementId,
                'inventory_cost_layer_id'=>$layer->id,
                'source_warehouse_id'=>$fromId,'destination_warehouse_id'=>$toId,
                'quantity'=>(string)$take->toScale(6),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }

    /**
     * Explicitly attribute a legacy layer's remaining quantity to one
     * physical warehouse or the unassigned pool. Never manufacture a lot or
     * increase stock, and never attribute beyond either available balance.
     */
    public function assignLegacy(
        int $layerId, ?int $warehouseId, string $quantity,
        string $reference, string $reason, int $actorId
    ): object {
        return DB::transaction(function () use ($layerId,$warehouseId,$quantity,$reference,$reason,$actorId) {
            $source = InventoryCostLayer::findOrFail($layerId);
            $material = InventoryMaterial::whereKey($source->inventory_material_id)->lockForUpdate()->firstOrFail();
            $layer = InventoryCostLayer::whereKey($layerId)->lockForUpdate()->firstOrFail();
            if ($warehouseId && ! CompanyWarehouse::whereKey($warehouseId)->where('is_active',true)->exists()) {
                throw ValidationException::withMessages(['company_warehouse_id'=>'Choose an active destination warehouse.']);
            }
            $qty = $this->amount($quantity);
            $positions = DB::table('inventory_cost_layer_locations')
                ->where('inventory_cost_layer_id',$layer->id)->lockForUpdate()->get();
            $positioned = BigDecimal::zero();
            foreach($positions as $position)$positioned=$positioned->plus((string)$position->quantity);
            $available = BigDecimal::of((string)$layer->remaining_quantity)->minus($positioned);
            if ($available->isLessThan($qty)) {
                throw ValidationException::withMessages(['quantity'=>'Attribution exceeds the remaining quantity of this source lot.']);
            }

            $balances = DB::table('inventory_warehouse_balances')
                ->where('inventory_material_id',$material->id)->lockForUpdate()->get();
            $allocated = BigDecimal::zero();$locationPhysical = BigDecimal::zero();
            foreach($balances as $balance) {
                $allocated=$allocated->plus((string)$balance->quantity);
                if($warehouseId && (int)$balance->company_warehouse_id===$warehouseId) {
                    $locationPhysical=BigDecimal::of((string)$balance->quantity);
                }
            }
            $global = BigDecimal::of((string)$material->on_hand_quantity);
            if($allocated->isGreaterThan($global)) {
                throw ValidationException::withMessages(['quantity'=>'Global and warehouse quantities must be reconciled first.']);
            }
            if($warehouseId===null)$locationPhysical=$global->minus($allocated);
            $located = DB::table('inventory_cost_layer_locations as position')
                ->join('inventory_cost_layers as layer','layer.id','=','position.inventory_cost_layer_id')
                ->where('layer.inventory_material_id',$material->id)
                ->where('position.location_key',$this->key($warehouseId))
                ->lockForUpdate()->pluck('position.quantity');
            $alreadyAttributed = BigDecimal::zero();
            foreach($located as $value)$alreadyAttributed=$alreadyAttributed->plus((string)$value);
            if($locationPhysical->minus($alreadyAttributed)->isLessThan($qty)) {
                throw ValidationException::withMessages([
                    'quantity'=>'This location lacks sufficient unattributed physical stock. Check the source count and existing lot positions.',
                ]);
            }

            $current = $positions->firstWhere('location_key',$this->key($warehouseId));
            if($current) {
                DB::table('inventory_cost_layer_locations')->where('id',$current->id)
                    ->update(['quantity'=>(string)BigDecimal::of((string)$current->quantity)
                        ->plus($qty)->toScale(6),'updated_at'=>now()]);
            } else {
                DB::table('inventory_cost_layer_locations')->insert([
                    'inventory_cost_layer_id'=>$layerId,'company_warehouse_id'=>$warehouseId,
                    'location_key'=>$this->key($warehouseId),'quantity'=>(string)$qty,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $id=DB::table('inventory_legacy_lot_assignments')->insertGetId([
                'inventory_cost_layer_id'=>$layerId,'company_warehouse_id'=>$warehouseId,
                'quantity'=>(string)$qty,'reference'=>$reference,'reason'=>$reason,
                'assigned_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('inventory.lot.position_reconciled','inventory_cost_layer',$layerId,
                'Legacy source lot attributed to a verified physical location.',
                ['assignment_id'=>$id,'material_id'=>$material->id,'warehouse_id'=>$warehouseId,
                 'quantity'=>(string)$qty,'reference'=>$reference]);
            return DB::table('inventory_legacy_lot_assignments')->where('id',$id)->first();
        });
    }
}
