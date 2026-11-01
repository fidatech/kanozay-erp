<?php

namespace App\Services;

use App\Models\GoodsReceipt;
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

            $receipt = GoodsReceipt::create([
                'number' => 'TMP-'.Str::uuid(),
                'purchase_order_id' => $locked->id,
                'receipt_token' => $data['receipt_token'],
                'received_on' => $data['received_on'],
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            foreach ($requested as $itemId => $quantity) {
                $item = $items->get($itemId);
                $material = InventoryMaterial::findOrFail($item->inventory_material_id);
                $line = $receipt->lines()->create([
                    'purchase_order_item_id' => $item->id,
                    'inventory_material_id' => $material->id,
                    'quantity' => (string) $quantity->toScale(6),
                    'unit' => $material->unit,
                ]);
                $this->stock->post($material, [
                    'type' => 'receipt', 'direction' => 'in',
                    'quantity' => (string) $quantity->toScale(6),
                    'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
                    'occurred_on' => $data['received_on'],
                    'notes' => 'Goods receipt '.$receipt->id.' / PO '.$locked->number,
                    'created_by' => $actorId,
                ]);
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

            return $receipt;
        });
    }
}
