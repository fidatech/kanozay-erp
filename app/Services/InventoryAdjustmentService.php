<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InventoryMaterial;
use App\Models\InventoryStockAdjustment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    public function post(InventoryMaterial $material, array $data, User $actor): InventoryStockAdjustment
    {
        return DB::transaction(function () use ($material, $data, $actor) {
            $lockedMaterial = InventoryMaterial::query()->lockForUpdate()->findOrFail($material->id);
            $existing = InventoryStockAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $sameRequest = $existing->inventory_material_id === $lockedMaterial->id
                    && $existing->direction === $data['direction']
                    && BigDecimal::of((string) $existing->quantity)->compareTo(BigDecimal::of((string) $data['quantity'])) === 0
                    && $existing->occurred_on->toDateString() === $data['occurred_on']
                    && $existing->reason === trim($data['reason'])
                    && (int)($existing->company_warehouse_id??0) === (int)($data['company_warehouse_id']??0);
                if (! $sameRequest) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different stock adjustment.']);
                }

                return $existing;
            }

            if (! in_array($data['direction'], ['adjustment_in', 'adjustment_out'], true)) {
                throw ValidationException::withMessages(['direction' => 'Select an increase or decrease adjustment.']);
            }

            $adjustment = InventoryStockAdjustment::create([
                'inventory_material_id' => $lockedMaterial->id,
                'direction' => $data['direction'],
                'quantity' => $data['quantity'],
                'occurred_on' => $data['occurred_on'],
                'reason' => trim($data['reason']),
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $actor->id,
                'company_warehouse_id'=>$data['company_warehouse_id']??null,
            ]);

            app(InventoryStockService::class)->post($lockedMaterial, [
                'type' => $data['direction'],
                'direction' => $data['direction'] === 'adjustment_in' ? 'in' : 'out',
                'quantity' => $data['quantity'],
                'company_warehouse_id'=>$data['company_warehouse_id']??null,
                'reference_type' => 'inventory_adjustment',
                'reference_id' => $adjustment->id,
                'occurred_on' => $data['occurred_on'],
                'notes' => $adjustment->reason,
                'created_by' => $actor->id,
            ]);

            ActivityLog::record('inventory.stock.adjusted', 'inventory_adjustment', $adjustment->id, 'Posted physical stock adjustment', [
                'material_sku' => $lockedMaterial->sku,
                'direction' => $adjustment->direction,
                'quantity' => (string) $adjustment->quantity,
            ]);

            return $adjustment;
        });
    }
}
