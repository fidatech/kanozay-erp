<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Services\SalesOrderFulfillmentService;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'reports.view']), 403);
        $orders=SalesOrder::with(['customer','currency','quotation'])->latest('id')->paginate(25);
        return view('sales.orders.index', [
            'orders'=>$orders,
            'fulfillment'=>app(SalesOrderFulfillmentService::class)->summarize($orders->getCollection()),
        ]);
    }

    public function show(Request $request, SalesOrder $order)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'reports.view']), 403);
        $order->load(['customer','quotation','lines','currency']);
        $snapshot=app(SalesOrderFulfillmentService::class)->summarize(collect([$order]));
        return view('sales.orders.show',[
            'order'=>$order,'fulfillment'=>$snapshot[$order->id],
        ]);
    }

    public function accept(Request $request, int $quotation, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['submission_token' => ['required', 'uuid']]);
        $order = $service->acceptQuotation($quotation, (int) $request->user()->id, $data['submission_token']);
        return redirect()->route('sales.orders.show', $order)->with('success', 'Quotation accepted and sales order confirmed. Inventory is not reserved or dispatched.');
    }
}
