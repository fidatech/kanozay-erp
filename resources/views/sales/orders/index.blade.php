@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Sales orders</h1><p>Confirmed from accepted customer quotations.</p></div></div>
<div class="card" style="padding:20px"><div class="table-wrap"><table><thead><tr><th>Order</th><th>Quotation</th><th>Customer</th><th>Date</th><th>Currency</th><th>Total</th><th>Status</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('sales.orders.show', $order) }}">{{ $order->number }}</a></td><td>{{ $order->quotation->number }}</td><td>{{ $order->customer->name }}</td><td>{{ $order->order_date->format('Y-m-d') }}</td><td>{{ $order->currency_code }}</td><td>{{ $order->currency_code }} {{ rtrim(rtrim((string)$order->total,'0'),'.') }}</td><td>{{ ucfirst($order->status) }}</td></tr>@empty<tr><td colspan="7" class="empty">No sales orders have been confirmed.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}</div>
@endsection
