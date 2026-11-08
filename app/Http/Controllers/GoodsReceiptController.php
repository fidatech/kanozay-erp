<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Support\LocalizedDecimal;
use Illuminate\Validation\Rule;
use App\Services\GoodsReceiptService;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function store(Request $request, PurchaseOrder $order, GoodsReceiptService $service)
    {
        abort_unless($request->user()->can('inventory.update'), 403);

        $request->merge(['quantities'=>LocalizedDecimal::quantities($request->input('quantities'))]);
        $data = $request->validate([
            'receipt_token' => ['required', 'uuid'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'company_warehouse_id' => ['nullable',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quantities' => ['required', 'array', 'min:1', 'max:100'],
            'quantities.*' => ['nullable', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,6})?$/'],
        ]);

        $receipt = $service->post($order, $data, $request->user()->id);

        return redirect()->route('purchases.orders.show', $order)
            ->with('success', 'Goods receipt '.$receipt->number.' posted to inventory. No financial entry was created.');
    }
}
