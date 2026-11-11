<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLandedCost;
use App\Services\GoodsReceiptLandedCostPostingService;
use App\Support\LocalizedDecimal;
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

        $request->merge(['amount'=>LocalizedDecimal::normalize($request->input('amount'))]);
        $data = $request->validate([
            'component' => ['required', Rule::in(['freight', 'customs', 'transport', 'handling', 'insurance', 'other'])],
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'import_shipment_id' => ['nullable', 'integer', Rule::exists('operational_registers', 'id')->where('workflow', 'purchases.shipments')],
        ]);

        $service->record($receipt->id, $data['component'], $data['amount'], $request->user()->id, $data['reference'] ?? null,
            ! empty($data['import_shipment_id']) ? (int) $data['import_shipment_id'] : null);

        return redirect()->route('purchases.orders.show', $order)
            ->with('success', 'Landed-cost allocation recorded for '.$receipt->number.'. Inventory cost layers now include this allocation; no general-ledger journal was posted.');
    }
    public function capitalize(Request $request, PurchaseOrder $order,GoodsReceipt $receipt,
        GoodsReceiptLandedCost $cost,GoodsReceiptLandedCostPostingService $service)
    {
        abort_unless($request->user()->can('administration.update') ||
            ($request->user()->can('finance.update') && $request->user()->can('purchases.view')),403);
        abort_unless((int)$receipt->purchase_order_id===(int)$order->id &&
            (int)$cost->goods_receipt_id===(int)$receipt->id,404);
        $data=$request->validate([
            'source_expense_journal_line_id'=>['required','integer','exists:journal_lines,id'],
            'accounting_date'=>['required','date','before_or_equal:today'],
            'approval_reference'=>['required','string','min:4','max:180'],
            'evidence_notes'=>['required','string','min:20','max:3000'],
            'confirm_source_expense'=>['accepted'],
        ]);
        $service->post($cost->id,$data,$request->user()->id);
        return redirect()->route('purchases.orders.show',$order)
            ->with('success','Original-currency landed cost reclassified from a verified posted expense debit to FIFO inventory, with no second goods receipt.');
    }

}
