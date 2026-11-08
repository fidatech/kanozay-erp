<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderCancellationService;
use Illuminate\Support\Facades\DB;
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
        $cancellation=DB::table('sales_order_cancellations')
            ->where('sales_order_id',$order->id)->first();
        return view('sales.orders.show',[
            'order'=>$order,'fulfillment'=>$snapshot[$order->id],
            'cancellation'=>$cancellation,
        ]);
    }

    public function cancel(Request $request,SalesOrder $order,SalesOrderCancellationService $service)
    {
        abort_unless($request->user()->canAny(['sales.update','administration.update']),403);
        $data=$request->validate([
            'submission_token'=>['required','uuid'],
            'reason'=>['required','string','min:10','max:1500'],
        ]);
        $service->cancel($order,$data,$request->user()->id);
        return redirect()->route('sales.orders.show',$order)
            ->with('success','Uninvoiced sales order cancelled with documented reason. No stock or accounting entry was reversed.');
    }

    public function accept(Request $request, int $quotation, SalesOrderService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate(['submission_token' => ['required', 'uuid']]);
        $order = $service->acceptQuotation($quotation, (int) $request->user()->id, $data['submission_token']);
        return redirect()->route('sales.orders.show', $order)->with('success', 'Quotation accepted and sales order confirmed. Inventory is not reserved or dispatched.');
    }
}
