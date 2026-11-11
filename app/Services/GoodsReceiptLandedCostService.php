<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLandedCost;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one immutable component allocation for an already posted GRN.
 * Only the receipt purchase currency is accepted. This is a cost snapshot,
 * not a stock valuation or general-ledger posting.
 */
class GoodsReceiptLandedCostService
{
    public function __construct(private readonly LandedCostAllocator $allocator) {}

    public function record(int $receiptId, string $component, string $amount, int $actorId, ?string $reference = null, ?int $shipmentId = null): GoodsReceiptLandedCost
    {
        if (! in_array($component, ['freight', 'customs', 'transport', 'handling', 'insurance', 'other'], true)) {
            throw ValidationException::withMessages(['component' => 'Unsupported landed-cost component.']);
        }
        try {
            $value = BigDecimal::of($amount);
            if (! $value->isGreaterThan(0) || ! $value->toScale(2)->isEqualTo($value)) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive amount with at most two decimals.']);
            }
        } catch (\Brick\Math\Exception\MathException $e) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount with at most two decimals.']);
        }

        return DB::transaction(function () use ($receiptId, $component, $value, $actorId, $reference, $shipmentId) {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receiptId);
            if ($receipt->status !== 'posted' || ! $receipt->cost_currency_code) {
                throw ValidationException::withMessages(['receipt' => 'A posted receipt with a captured purchase currency is required.']);
            }
            if (GoodsReceiptLandedCost::where('goods_receipt_id', $receiptId)->where('component', $component)->exists()) {
                throw ValidationException::withMessages(['component' => 'This component has already been recorded for the receipt.']);
            }
            if ($shipmentId !== null) {
                // The charge may only be tied to a documented, non-rejected import shipment
                // of the same purchase order as this receipt.
                $shipment = \App\Models\OperationalRegister::whereKey($shipmentId)->lockForUpdate()->first();
                if (! $shipment || $shipment->workflow !== 'purchases.shipments' || $shipment->status === 'rejected'
                    || (int) ($shipment->data['purchase_order'] ?? 0) !== (int) $receipt->purchase_order_id) {
                    throw ValidationException::withMessages(['import_shipment_id' => 'Choose a documented import shipment of this purchase order that has not been rejected.']);
                }
            }
            $lines = $receipt->lines()->orderBy('id')->get();
            if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->line_subtotal_snapshot === null)) {
                throw ValidationException::withMessages(['receipt' => 'Historical receipt costs are missing; allocation is unavailable.']);
            }
            $costing = app(InventoryCostService::class);
            foreach ($lines as $line) $costing->assertLandedCostAllowed((int) $line->id);
            $weights = $lines->mapWithKeys(fn ($line) => [$line->id => (string) $line->line_subtotal_snapshot])->all();
            try {
                $allocations = $this->allocator->allocate((string) $value, $weights);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['receipt' => $e->getMessage()]);
            }
            $cost = GoodsReceiptLandedCost::create([
                'goods_receipt_id' => $receiptId,
                'component' => $component,
                'currency_code' => $receipt->cost_currency_code,
                'amount' => (string) $value->toScale(2),
                'reference' => $reference,
                'import_shipment_id' => $shipmentId,
                'created_by' => $actorId,
            ]);
            foreach ($allocations as $lineId => $allocated) {
                $cost->allocations()->create(['goods_receipt_line_id' => $lineId, 'amount' => $allocated]);
            }
            return $cost->load('allocations');
        });
    }
}
