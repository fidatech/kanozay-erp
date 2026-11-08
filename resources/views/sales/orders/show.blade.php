@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>{{ $order->number }}</h1><p>Confirmed sales order · {{ ucfirst($order->status) }}</p></div><a class="btn btn-light" href="{{ route('sales.orders.index') }}">Back to orders</a></div>
<div class="card" style="padding:22px"><div class="form-grid"><div><strong>Customer</strong><div>{{ $order->customer->name }}</div></div><div><strong>Quotation</strong><div><a href="{{ route('sales.quotations.show', $order->quotation) }}">{{ $order->quotation->number }}</a></div></div><div><strong>Order date</strong><div>{{ $order->order_date->format('Y-m-d') }}</div></div><div><strong>Total</strong><div>{{ $order->currency_code }} {{ rtrim(rtrim((string)$order->total,'0'),'.') }}</div></div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-top:20px">
@foreach(['Ordered packages'=>'ordered','Posted invoice packages'=>'invoiced','Customer received packages'=>'delivered','Credit-returned packages'=>'returned','Remaining to invoice'=>'remaining_to_invoice'] as $name=>$field)
<div style="border:1px solid #dce7f2;border-radius:9px;padding:13px">
<div class="muted">{{ $name }}</div><strong style="font-size:21px">{{ number_format($fulfillment[$field]) }}</strong>
</div>
@endforeach
</div>
<div class="table-wrap" style="margin-top:20px"><table>
<thead><tr><th>Description</th><th>Ordered</th><th>Unit</th><th>Unit price</th><th>Line total</th><th>Invoiced</th><th>Delivery documented</th><th>Proof-delivered</th><th>Credited returns</th><th>Still to invoice</th></tr></thead>
<tbody>
@foreach($order->lines as $line)
@php($progress=$fulfillment['lines'][$line->id]??null)
<tr><td>{{ $line->description }}</td><td>{{ number_format($line->quantity) }}</td><td>{{ $line->unit }}</td>
<td>{{ $order->currency_code }} {{ rtrim(rtrim((string)$line->unit_price,'0'),'.') }}</td>
<td>{{ $order->currency_code }} {{ rtrim(rtrim((string)$line->line_total,'0'),'.') }}</td>
<td>{{ $progress['invoiced']??0 }}</td>
<td>{{ $progress['documented']??0 }}</td>
<td>{{ $progress['delivered']??0 }}</td>
<td>{{ $progress['returned']??0 }}</td>
<td>{{ $progress['remaining_to_invoice']??$line->quantity }}</td></tr>
@endforeach
</tbody></table></div>
<p class="muted">This order retains the original accepted quotation. Inventory is issued when a posted sales invoice dispatches released finished goods. Delivery notes document logistics separately and never issue inventory again. Customer credits are displayed independently; they do not automatically reopen the order or imply physically restocked merchandise.</p>
@can('sales.create')
<a class="btn btn-light" href="{{ route('sales.invoices.create') }}">Create sales invoice</a>
@endcan</div>
@endsection
