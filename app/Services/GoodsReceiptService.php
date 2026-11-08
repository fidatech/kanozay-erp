<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMaterial;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function __construct(private readonly InventoryStockService $stock) {}

    /**
     * A GRN is posted atomically with its stock ledger entries. All quantities
     * use the linked inventory material's native unit; no conversion occurs.
     */
    public function post(PurchaseOrder $order, array $data, int $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $data, $actorId) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (\Carbon\CarbonImmutable::parse($data['received_on'])->startOfDay()
                ->isBefore(\Carbon\CarbonImmutable::parse($locked->order_date)->startOfDay())) {
                throw ValidationException::withMessages(['received_on' => 'Goods cannot be received before the purchase-order date.']);
            }
            if (GoodsReceipt::where('receipt_token', $data['receipt_token'])->exists()) {
                throw ValidationException::withMessages(['receipt_token' => 'This goods receipt was already submitted.']);
            }
            if (! in_array($locked->status, ['confirmed', 'partially_received'], true)) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Only confirmed purchase orders can receive goods.']);
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $requested = [];
            foreach ($data['quantities'] as $itemId => $raw) {
                if ($raw === null || $raw === '') {
                    continue;
                }
                if (! ctype_digit((string) $itemId) || ! $items->has((int) $itemId)) {
                    throw ValidationException::withMessages(['quantities' => 'Receipt contains an item outside this purchase order.']);
                }
                $value = BigDecimal::of((string) $raw);
                if (! $value->isGreaterThan(0)) {
                    throw ValidationException::withMessages(['quantities' => 'Received quantities must be greater than zero.']);
                }
                $requested[(int) $itemId] = $value;
            }
            if ($requested === []) {
                throw ValidationException::withMessages(['quantities' => 'Enter at least one received quantity.']);
            }

            $existing = GoodsReceiptLine::whereIn('purchase_order_item_id', $items->keys()->all())
                ->get()->groupBy('purchase_order_item_id');
            $receivedByItem = [];
            foreach ($items as $item) {
                $total = BigDecimal::zero();
                foreach ($existing->get($item->id, collect()) as $line) {
                    $total = $total->plus(BigDecimal::of((string) $line->quantity));
                }
                $receivedByItem[$item->id] = $total;
            }

            foreach ($requested as $itemId => $quantity) {
                $item = $items->get($itemId);
                if (! $item->inventory_material_id) {
                    throw ValidationException::withMessages(['quantities' => 'Link the purchase item to an inventory material before receiving it.']);
                }
                $remaining = BigDecimal::of((string) $item->quantity)->minus($receivedByItem[$itemId]);
                if ($quantity->isGreaterThan($remaining)) {
                    throw ValidationException::withMessages(['quantities' => 'Received quantity exceeds the outstanding ordered quantity.']);
                }
                $receivedByItem[$itemId] = $receivedByItem[$itemId]->plus($quantity);
            }

            // Only a single configured base currency and an explicit direct
            // historical rate can produce a reliable conversion snapshot.
            // Missing/ambiguous rates must never be guessed or applied later.
            $baseCurrencies = Currency::query()->where('is_base', true)->limit(2)->get();
            $base = $baseCurrencies->count() === 1 ? $baseCurrencies->first() : null;
            $rate = null;
            $fxRate = null;
            if ($base && (int) $base->id === (int) $locked->currency_id) {
                $fxRate = '1.000000000000';
            } elseif ($base) {
                $candidate = ExchangeRate::query()
                    ->where('from_currency_id', $locked->currency_id)
                    ->where('to_currency_id', $base->id)
                    ->whereDate('effective_date', '<=', $data['received_on'])
                    ->orderByDesc('effective_date')
                    ->orderByDesc('id')
                    ->first();
                if ($candidate && BigDecimal::of((string) $candidate->rate)->isGreaterThan(0)) {
                    $rate = $candidate;
                    $fxRate = (string) BigDecimal::of((string) $rate->rate)->toScale(12);
                }
            }

            $receipt = GoodsReceipt::create([
                'number' => 'TMP-'.Str::uuid(),
                'purchase_order_id' => $locked->id,
                'receipt_token' => $data['receipt_token'],
                'received_on' => $data['received_on'],
                'company_warehouse_id' => $data['company_warehouse_id']??null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $actorId,
                // Keep the purchase currency with the receipt. Historical GRNs
                // must not be revalued if currency settings later change.
                'cost_currency_id' => $locked->currency_id,
                'cost_currency_code' => $locked->currency()->firstOrFail()->code,
                'valuation_currency_id' => $base?->id,
                'valuation_currency_code' => $base?->code,
                'fx_rate_snapshot' => $fxRate,
                'fx_rate_source_id' => $rate?->id,
                'fx_rate_effective_date' => $rate?->effective_date?->toDateString(),
            ]);

            foreach ($requested as $itemId => $quantity) {
                $item = $items->get($itemId);
                $material = InventoryMaterial::findOrFail($item->inventory_material_id);
                $line = $receipt->lines()->create([
                    'purchase_order_item_id' => $item->id,
                    'inventory_material_id' => $material->id,
                    'quantity' => (string) $quantity->toScale(6),
                    'unit' => $material->unit,
                    // Exact PO-currency purchase price, excluding freight,
                    // other landed costs, tax and any FX conversion.
                    'unit_price_snapshot' => (string) BigDecimal::of((string) $item->unit_price)->toScale(4),
                    'line_subtotal_snapshot' => (string) BigDecimal::of((string) $item->unit_price)
                        ->multipliedBy($quantity)->toScale(10),
                ]);
                $this->stock->post($material, [
                    'type' => 'receipt', 'direction' => 'in',
                    'company_warehouse_id' => $data['company_warehouse_id']??null,
                    'quantity' => (string) $quantity->toScale(6),
                    'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
                    'occurred_on' => $data['received_on'],
                    'notes' => 'Goods receipt '.$receipt->id.' / PO '.$locked->number,
                    'created_by' => $actorId,
                ]);
                app(InventoryCostService::class)->receiveGoodsReceiptLine($line->id, $actorId);
            }

            $allReceived = $items->every(function ($item) use ($receivedByItem) {
                return $item->inventory_material_id !== null
                    && $receivedByItem[$item->id]->isEqualTo(BigDecimal::of((string) $item->quantity));
            });
            $receipt->update([
                'number' => 'GRN-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT),
                'status' => 'posted',
            ]);
            $locked->update(['status' => $allReceived ? 'received' : 'partially_received']);
            ActivityLog::record('purchases.goods.received', 'goods_receipt', $receipt->id,
                'GRN '.$receipt->number.' posted for '.$locked->number,
                ['purchase_order_id'=>$locked->id, 'warehouse_id'=>$data['company_warehouse_id']??null,
                 'lines'=>count($requested), 'currency_id'=>$locked->currency_id]);

            return $receipt;
        });
    }
}
