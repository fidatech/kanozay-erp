<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionMaterialWaste;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionWasteService
{
    /**
     * Waste is a classification of material already issued to production.
     * It must never post a second inventory deduction.
     */
    public function record(ProductionOrder $order, ProductionOrderMaterial $line, string $quantity, string $reason, string $token, int $actor): void
    {
        DB::transaction(function () use ($order, $line, $quantity, $reason, $token, $actor) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['issued', 'pending_qc'], true)) {
                $this->reject('Material waste can only be recorded after issue and before laboratory approval.');
            }
            $line = ProductionOrderMaterial::query()->lockForUpdate()->findOrFail($line->id);
            if ($line->production_order_id !== $order->id) {
                $this->reject('The material line does not belong to this production order.');
            }
            if (ProductionMaterialWaste::query()->where('submission_token', $token)->exists()) {
                $this->reject('This waste submission has already been recorded.');
            }
            $amount = BigDecimal::of($quantity);
            if (! $amount->isGreaterThan(0)) {
                $this->reject('Rejected quantity must be greater than zero.');
            }
            $total = BigDecimal::zero();
            foreach ($line->wastes()->get(['quantity']) as $waste) {
                $total = $total->plus(BigDecimal::of((string) $waste->quantity));
            }
            if ($total->plus($amount)->isGreaterThan(BigDecimal::of((string) $line->issued_quantity))) {
                $this->reject('Total rejected material cannot exceed the quantity already issued.');
            }
            $line->wastes()->create([
                'submission_token' => $token,
                'quantity' => (string) $amount->toScale(6),
                'reason' => $reason,
                'recorded_by' => $actor,
            ]);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production_waste' => $message]);
    }
}
