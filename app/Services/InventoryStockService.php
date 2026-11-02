<?php

namespace App\Services;

use App\Models\InventoryMaterial;
use App\Models\InventoryStockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InventoryStockService
{
    /**
     * Append an immutable stock movement and update the balance under a row lock.
     * The reference must identify a unique source line, not an entire document.
     */
    public function post(InventoryMaterial $material, array $data): InventoryStockMovement
    {
        return DB::transaction(function () use ($material, $data) {
            $locked = InventoryMaterial::query()->lockForUpdate()->findOrFail($material->id);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['inventory_material_id' => 'Inactive materials cannot receive stock movements.']);
            }

            $directions = [
                'opening' => 'in', 'receipt' => 'in', 'issue' => 'out',
                'adjustment_in' => 'in', 'adjustment_out' => 'out',
            ];
            $type = $data['type'] ?? '';
            if (! isset($directions[$type]) || ($data['direction'] ?? null) !== $directions[$type]) {
                throw new InvalidArgumentException('Invalid stock movement type or direction.');
            }

            $raw = (string) ($data['quantity'] ?? '');
            if (! preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', $raw)) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must have at most six decimal places.']);
            }
            $quantity = BigDecimal::of($raw);
            if (! $quantity->isGreaterThan(0)) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
            }
            if ($type === 'opening' && $locked->movements()->where('type', 'opening')->exists()) {
                throw ValidationException::withMessages(['quantity' => 'Opening stock has already been posted for this material.']);
            }

            $balance = BigDecimal::of((string) $locked->on_hand_quantity);
            $next = $directions[$type] === 'in' ? $balance->plus($quantity) : $balance->minus($quantity);
            if ($next->isLessThan(0)) {
                throw ValidationException::withMessages(['quantity' => 'Stock issue exceeds the available quantity.']);
            }
            if ($directions[$type] === 'out' && $next->isLessThan(BigDecimal::of((string) $locked->reserved_quantity))) {
                throw ValidationException::withMessages(['quantity' => 'Stock is reserved for production orders.']);
            }
            if ($next->isGreaterThan(BigDecimal::of('99999999999999.999999'))) {
                throw ValidationException::withMessages(['quantity' => 'Stock balance exceeds the supported range.']);
            }

            $entry = $locked->movements()->create([
                'type' => $type,
                'direction' => $directions[$type],
                'quantity' => (string) $quantity->toScale(6),
                'reference_type' => $data['reference_type'],
                'reference_id' => $data['reference_id'],
                'occurred_on' => $data['occurred_on'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);
            $locked->forceFill(['on_hand_quantity' => (string) $next->toScale(6)])->save();

            if ($type === 'opening') {
                app(InventoryCostService::class)->createFromInboundMovement(
                    $entry,
                    isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
                    isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                    isset($data['created_by']) ? (int) $data['created_by'] : null,
                );
            }

            if ($directions[$type] === 'out') {
                app(InventoryCostService::class)->consume($entry);
            }

            return $entry;
        });
    }
}
