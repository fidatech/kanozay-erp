<?php

namespace App\Services;

use App\Models\InventoryMaterial;
use App\Models\ProductionOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionMaterialService
{
    public function __construct(private readonly InventoryStockService $stock) {}

    /**
     * Reserve a complete production plan. Inventory material locks are acquired
     * in ascending ID order to avoid deadlocks between competing plans.
     */
    public function reserve(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertStatus($order, 'planned');
            $lines = $order->materials()->orderBy('id')->get();
            $requirements = $this->requirements($lines);
            $materials = $this->lockMaterials(array_keys($requirements));

            foreach ($requirements as $id => $required) {
                $material = $materials->get($id);
                if (! $material || ! $material->is_active) {
                    $this->reject('A linked material is missing or inactive.');
                }
                $available = BigDecimal::of((string) $material->on_hand_quantity)
                    ->minus(BigDecimal::of((string) $material->reserved_quantity));
                if ($available->isLessThan($required)) {
                    $this->reject('Insufficient unreserved stock for '.$material->sku.'.');
                }
            }

            foreach ($requirements as $id => $required) {
                $material = $materials->get($id);
                $material->forceFill([
                    'reserved_quantity' => (string) BigDecimal::of((string) $material->reserved_quantity)
                        ->plus($required)->toScale(6),
                ])->save();
            }
            foreach ($lines as $line) {
                $line->update(['reserved_quantity' => (string) $line->required_quantity]);
            }
            $order->update(['status' => 'reserved']);
        });
    }

    /**
     * Post one final material issue per production order. Actual usage can be
     * lower or higher than the plan, provided other reservations remain covered.
     * The stock ledger and reservation release share one database transaction.
     */
    public function issue(ProductionOrder $order, array $actuals, int $actorId, string $date, array $warehouseIds = []): void
    {
        DB::transaction(function () use ($order, $actuals, $actorId, $date, $warehouseIds) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertStatus($order, 'reserved');
            $lines = $order->materials()->orderBy('id')->get();
            $reserved = $this->requirements($lines, 'reserved_quantity');
            $actualByMaterial = [];
            $actualByLine = [];

            if (count($actuals) !== $lines->count()) {
                $this->reject('Enter an actual quantity for every material line.');
            }
            foreach ($lines as $line) {
                $raw = $actuals[$line->id] ?? null;
                if (! is_scalar($raw) || ! preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', (string) $raw)) {
                    $this->reject('Actual quantities must be non-negative with at most six decimals.');
                }
                $quantity = BigDecimal::of((string) $raw);
                $actualByLine[$line->id] = $quantity;
                $id = $line->inventory_material_id;
                $actualByMaterial[$id] = ($actualByMaterial[$id] ?? BigDecimal::zero())->plus($quantity);
            }
            if (! collect($actualByLine)->contains(fn ($quantity) => $quantity->isGreaterThan(0))) {
                $this->reject('At least one actual quantity must be greater than zero.');
            }

            $materials = $this->lockMaterials(array_keys($reserved));
            foreach ($reserved as $id => $quantity) {
                $material = $materials->get($id);
                if (! $material || ! $material->is_active) {
                    $this->reject('A reserved material is missing or inactive.');
                }
                $otherReservations = BigDecimal::of((string) $material->reserved_quantity)->minus($quantity);
                if ($otherReservations->isLessThan(0)) {
                    $this->reject('Reserved stock totals are inconsistent.');
                }
                $remaining = BigDecimal::of((string) $material->on_hand_quantity)
                    ->minus($actualByMaterial[$id] ?? BigDecimal::zero());
                if ($remaining->isLessThan($otherReservations)) {
                    $this->reject('Actual issue would consume stock reserved for another order: '.$material->sku.'.');
                }
            }

            foreach ($reserved as $id => $quantity) {
                $material = $materials->get($id);
                $material->forceFill([
                    'reserved_quantity' => (string) BigDecimal::of((string) $material->reserved_quantity)
                        ->minus($quantity)->toScale(6),
                ])->save();
            }
            foreach ($lines as $line) {
                $quantity = $actualByLine[$line->id];
                $line->update([
                    'reserved_quantity' => '0.000000',
                    'issued_quantity' => (string) $quantity->toScale(6),
                ]);
                if ($quantity->isGreaterThan(0)) {
                    $this->stock->post($materials->get($line->inventory_material_id), [
                        'type' => 'issue', 'direction' => 'out',
                        'company_warehouse_id' => $warehouseIds[$line->id]??null,
                        'quantity' => (string) $quantity->toScale(6),
                        'reference_type' => 'production_order_material',
                        'reference_id' => $line->id,
                        'occurred_on' => $date,
                        'notes' => 'Production material issue for '.$order->number,
                        'created_by' => $actorId,
                    ]);
                }
            }
            $order->update(['status' => 'issued']);
        });
    }

    public function cancel(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'planned') {
                $order->update(['status' => 'cancelled']);
                return;
            }
            $this->assertStatus($order, 'reserved');
            $lines = $order->materials()->orderBy('id')->get();
            $reserved = $this->requirements($lines, 'reserved_quantity');
            $materials = $this->lockMaterials(array_keys($reserved));
            foreach ($reserved as $id => $quantity) {
                $material = $materials->get($id);
                $next = BigDecimal::of((string) $material->reserved_quantity)->minus($quantity);
                if ($next->isLessThan(0)) {
                    $this->reject('Reserved stock totals are inconsistent.');
                }
                $material->forceFill(['reserved_quantity' => (string) $next->toScale(6)])->save();
            }
            foreach ($lines as $line) {
                $line->update(['reserved_quantity' => '0.000000']);
            }
            $order->update(['status' => 'cancelled']);
        });
    }

    private function requirements($lines, string $field = 'required_quantity'): array
    {
        if ($lines->isEmpty()) {
            $this->reject('This order has no saved material requirements.');
        }
        $grouped = [];
        foreach ($lines as $line) {
            if (! $line->inventory_material_id) {
                $this->reject('Link every production line to an inventory material first.');
            }
            $id = $line->inventory_material_id;
            $grouped[$id] = ($grouped[$id] ?? BigDecimal::zero())
                ->plus(BigDecimal::of((string) $line->{$field}));
        }
        ksort($grouped, SORT_NUMERIC);
        return $grouped;
    }

    private function lockMaterials(array $ids)
    {
        sort($ids, SORT_NUMERIC);
        return InventoryMaterial::query()->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function assertStatus(ProductionOrder $order, string $expected): void
    {
        if ($order->status !== $expected) {
            $this->reject('Production order must be '.$expected.' for this action.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production_order' => $message]);
    }
}
