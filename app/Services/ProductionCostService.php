<?php

namespace App\Services;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryStockMovement;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class ProductionCostService
{
    /** Freeze issued raw-material costs by their captured currency when a QC-approved batch is released. */
    public function snapshot(ProductionBatch $batch): Collection
    {
        if ($batch->costs()->exists()) return $batch->costs()->orderBy('currency_code')->get();

        $materialLines = $batch->order->materials()->get(['id','is_packaging']);
        $lineIds = $materialLines->pluck('id');
        if ($lineIds->isEmpty()) return collect();
        $isPackaging=$materialLines->pluck('is_packaging','id');

        $movements = InventoryStockMovement::where('reference_type', 'production_order_material')
            ->whereIn('reference_id', $lineIds)->orderBy('id')->get();
        $totals = [];
        foreach ($movements as $movement) {
            $consumptions = InventoryCostConsumption::where('inventory_stock_movement_id', $movement->id)->get();
            if ($consumptions->isEmpty()) {
                $this->addUnvalued($totals, (string) $movement->quantity);
                continue;
            }
            foreach ($consumptions as $consumption) {
                if (! $consumption->is_valued || $consumption->currency_id === null || $consumption->currency_code === null) {
                    $this->addUnvalued($totals, (string) $consumption->quantity);
                    continue;
                }
                $key = $consumption->currency_id.':'.$consumption->currency_code;
                $totals[$key] ??= [
                    'currency_id' => $consumption->currency_id,
                    'currency_code' => $consumption->currency_code,
                    'material_cost' => BigDecimal::zero(),
                    'ingredient_cost' => BigDecimal::zero(),
                    'packaging_cost' => BigDecimal::zero(),
                    'consumed_quantity' => BigDecimal::zero(),
                    'unvalued_quantity' => BigDecimal::zero(),
                ];
                $totals[$key]['material_cost'] = $totals[$key]['material_cost']->plus((string) $consumption->amount);
                $category = $isPackaging->get($movement->reference_id) ? 'packaging_cost' : 'ingredient_cost';
                $totals[$key][$category] = $totals[$key][$category]->plus((string)$consumption->amount);
                $totals[$key]['consumed_quantity'] = $totals[$key]['consumed_quantity']->plus((string) $consumption->quantity);
            }
        }

        foreach ($totals as $key => $cost) {
            if ($key === 'unvalued') continue;
            ProductionBatchCost::create([
                'production_batch_id' => $batch->id,
                'currency_id' => $cost['currency_id'],
                'currency_code' => $cost['currency_code'],
                'material_cost' => (string) $cost['material_cost']->toScale(10, RoundingMode::HalfUp),
                'ingredient_cost' => (string) $cost['ingredient_cost']->toScale(10,RoundingMode::HalfUp),
                'packaging_cost' => (string) $cost['packaging_cost']->toScale(10,RoundingMode::HalfUp),
                'consumed_quantity' => (string) $cost['consumed_quantity']->toScale(6, RoundingMode::HalfUp),
                'unvalued_quantity' => (string) $cost['unvalued_quantity']->toScale(6, RoundingMode::HalfUp),
            ]);
        }
        if (isset($totals['unvalued'])) {
            ProductionBatchCost::create([
                'production_batch_id' => $batch->id,
                'currency_id' => null,
                'currency_code' => null,
                'material_cost' => null,
                'consumed_quantity' => '0.000000',
                'unvalued_quantity' => (string) $totals['unvalued']['unvalued_quantity']->toScale(6, RoundingMode::HalfUp),
            ]);
        }

        return $batch->costs()->orderBy('currency_code')->get();
    }

    private function addUnvalued(array &$totals, string $quantity): void
    {
        $totals['unvalued'] ??= ['unvalued_quantity' => BigDecimal::zero()];
        $totals['unvalued']['unvalued_quantity'] = $totals['unvalued']['unvalued_quantity']->plus($quantity);
    }
}
