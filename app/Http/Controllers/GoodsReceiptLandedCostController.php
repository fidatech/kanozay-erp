<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptLandedCostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoodsReceiptLandedCostController extends Controller
{
    public function store(Request $request, PurchaseOrder $order, GoodsReceipt $receipt, GoodsReceiptLandedCostService $service)
    {
        abort_unless($request->user()->can('inventory.update'), 403);
        abort_unless((int) $receipt->purchase_order_id === (int) $order->id, 404);

        $data = $request->validate([
            'component' => ['required', Rule::in(['freight', 'customs', 'transport', 'handling', 'insurance', 'other'])],
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $service->record($receipt->id, $data['component'], $data['amount'], $request->user()->id, $data['reference'] ?? null);

        return redirect()->route('purchases.orders.show', $order)
            ->with('success', 'Landed-cost allocation recorded for '.$receipt->number.'. Inventory cost layers now include this allocation; no general-ledger journal was posted.');
    }
}
