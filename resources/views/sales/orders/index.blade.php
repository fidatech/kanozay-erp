@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Sales orders</h1><p>Confirmed from accepted customer quotations.</p></div></div>
<div class="card" style="padding:20px"><p class="muted">Invoiced means the accepted order quantity billed on posted invoices. Proof-delivered means customer receipt confirmed on delivery notes. Credit returns remain visible separately and do not reopen the order automatically.</p><div class="table-wrap"><table><thead><tr><th>Order</th><th>Quotation</th><th>Customer</th><th>Date</th><th>Currency</th><th>Total</th><th>Invoiced / ordered</th><th>Proof-delivered</th><th>Credits returned</th><th>Status</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('sales.orders.show', $order) }}">{{ $order->number }}</a></td><td>{{ $order->quotation->number }}</td><td>{{ $order->customer->name }}</td><td>{{ $order->order_date->format('Y-m-d') }}</td><td>{{ $order->currency_code }}</td><td>{{ $order->currency_code }} {{ rtrim(rtrim((string)$order->total,'0'),'.') }}</td>
@php($progress=$fulfillment[$order->id])
<td>{{ $progress['invoiced'] }} / {{ $progress['ordered'] }} packages
@if($progress['remaining_to_invoice']>0)<div class="muted">{{ $progress['remaining_to_invoice'] }} remaining to invoice</div>@endif</td>
<td>{{ $progress['delivered'] }} packages</td>
<td>{{ $progress['returned'] }} packages</td>
<td>{{ ucfirst($order->status) }}</td></tr>@empty<tr><td colspan="10" class="empty">No sales orders have been confirmed.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}</div>
@endsection
