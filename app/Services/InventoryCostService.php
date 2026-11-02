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
use Illuminate\Validation\ValidationException;

class InventoryCostService
{
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
        );
    }

    public function createFromInboundMovement(InventoryStockMovement $movement, ?string $unitCost, ?int $currencyId, int $actorId): InventoryCostLayer
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
        );
    }

    public function consume(InventoryStockMovement $movement): void
    {
        if (InventoryCostConsumption::where('inventory_stock_movement_id', $movement->id)->exists()) return;
        $quantityToConsume = BigDecimal::of((string) $movement->quantity);
            $layers = InventoryCostLayer::query()->where('inventory_material_id', $movement->inventory_material_id)
                ->where('remaining_quantity', '>', 0)->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();

            foreach ($layers as $layer) {
                if (! $quantityToConsume->isGreaterThan(0)) break;
                $remaining = BigDecimal::of((string) $layer->remaining_quantity);
                $quantity = $remaining->compareTo($quantityToConsume) <= 0 ? $remaining : $quantityToConsume;
                $amount = $this->amountForConsumption($layer, $quantity);
                InventoryCostConsumption::create([
                    'inventory_stock_movement_id' => $movement->id,
                    'inventory_cost_layer_id' => $layer->id,
                    'quantity' => (string) $quantity->toScale(6),
                    'amount' => $amount,
                    'currency_id' => $layer->currency_id,
                    'currency_code' => $layer->currency_code,
                    'is_valued' => $layer->original_cost !== null && $layer->currency_id !== null,
                ]);
                $layer->update(['remaining_quantity' => (string) $remaining->minus($quantity)->toScale(6)]);
                $quantityToConsume = $quantityToConsume->minus($quantity);
            }

            if ($quantityToConsume->isGreaterThan(0)) {
                // Physical balances may predate valuation-layer tracking. Keep the issue posted and expose this quantity as unvalued.
                InventoryCostConsumption::create([
                    'inventory_stock_movement_id' => $movement->id,
                    'inventory_cost_layer_id' => null,
                    'quantity' => (string) $quantityToConsume->toScale(6),
                    'amount' => null,
                    'currency_id' => null,
                    'currency_code' => null,
                    'is_valued' => false,
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

    private function createLayer(int $materialId, string $sourceType, int $sourceId, string $quantity, ?string $cost, ?int $currencyId, ?string $currencyCode, string $date, int $actorId): InventoryCostLayer
    {
        return InventoryCostLayer::firstOrCreate(
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
    }

    private function amountForConsumption(InventoryCostLayer $layer, BigDecimal $quantity): ?string
    {
        if ($layer->original_cost === null || $layer->currency_id === null) return null;
        $landed = $layer->source_type === 'goods_receipt_line'
            ? DB::table('goods_receipt_landed_cost_allocations')->where('goods_receipt_line_id', $layer->source_id)->sum('amount')
            : '0';
        $totalCost = BigDecimal::of((string) $layer->original_cost)->plus((string) $landed);
        $used = BigDecimal::zero();
        foreach ($layer->consumptions()->where('is_valued', true)->pluck('amount') as $prior) $used = $used->plus((string) $prior);
        $availableCost = $totalCost->minus($used);
        $remaining = BigDecimal::of((string) $layer->remaining_quantity);
        if ($quantity->compareTo($remaining) === 0) return (string) $availableCost->toScale(10, RoundingMode::HalfUp);
        return (string) $availableCost->multipliedBy($quantity)->dividedBy((string) $remaining, 10, RoundingMode::HalfUp)->toScale(10, RoundingMode::HalfUp);
    }
}
