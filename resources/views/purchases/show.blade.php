@extends('layouts.kanozay')
@section('content')
<style>
.po-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.po-card{background:#fff;border:1px solid #dbe5f0;border-radius:12px;padding:18px}.po-card small{color:#648099;display:block;margin-bottom:8px}.po-card strong{font-size:20px;color:#17466f}.po-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px}.po-form label{font-size:12px;font-weight:700;color:#486680;display:grid;gap:5px}.po-form input,.po-form select{width:100%;padding:10px;border:1px solid #d0ddeb;border-radius:7px}.po-form button{background:#1b6caa;color:#fff;border:0;border-radius:8px;padding:11px;align-self:end;cursor:pointer}.po-notice{padding:13px 16px;background:#f0f6fd;border:1px solid #d3e4f6;border-radius:8px;margin:12px 0;color:#315e81}.po-error{color:#a33325;background:#fff1ed;padding:12px;border-radius:8px}@media(max-width:900px){.po-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.po-form{grid-template-columns:1fr 1fr}}@media(max-width:600px){.po-cards,.po-form{grid-template-columns:1fr}}
</style>
@php
$currency=$order->currency?->code;
$subtotal=$order->items->sum(fn($i)=>(float)$i->quantity*(float)$i->unit_price);
$expenses=$order->expenses->sum('amount');
$advances=$order->advances->sum('amount');
$landed=$subtotal+$expenses;
$balance=$subtotal-$advances;
@endphp
<div class="heading"><div><h1>Purchase Order {{ $order->number }}</h1><p>{{ ucfirst($order->source) }} purchase · {{ ucfirst($order->status) }} · {{ $order->order_date }}</p></div><a href="{{ route('purchases.orders.index') }}">Back to Orders</a></div>
@if(session('success'))<div class="po-notice">{{ session('success') }}</div>@endif
@if($errors->any())<div class="po-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="panel"><h2>Purchase Details</h2><p><strong>Supplier:</strong> {{ $order->supplier?->name }} &nbsp; <strong>Currency:</strong> {{ $currency }} &nbsp; <strong>Expected delivery:</strong> {{ $order->expected_date ?? 'Not specified' }}</p><p>{{ $order->notes }}</p></div>
<div class="po-cards">
<div class="po-card"><small>Items subtotal</small><strong>{{ number_format($subtotal,2) }} {{ $currency }}</strong></div>
<div class="po-card"><small>Purchase expenses</small><strong>{{ number_format($expenses,2) }} {{ $currency }}</strong></div>
<div class="po-card"><small>Estimated landed cost</small><strong>{{ number_format($landed,2) }} {{ $currency }}</strong></div>
<div class="po-card"><small>Supplier balance (unposted)</small><strong>{{ number_format($balance,2) }} {{ $currency }}</strong></div>
</div>
<div class="panel"><h2>Items and Estimated Landed Cost</h2><div class="table-wrap"><table><thead><tr><th>Description</th><th>Quantity</th><th>Unit Price</th><th>Line Total</th><th>Allocated Expenses</th><th>Estimated Landed Total</th></tr></thead><tbody>
@foreach($order->items as $item)
@php($line=(float)$item->quantity*(float)$item->unit_price)
@php($allocated=$subtotal>0 ? $expenses*$line/$subtotal : 0)
<tr><td>{{ $item->description }}</td><td>{{ number_format((float)$item->quantity,4) }}</td><td>{{ number_format((float)$item->unit_price,4) }}</td><td>{{ number_format($line,2) }}</td><td>{{ $subtotal>0 ? number_format($allocated,2) : 'Pending' }}</td><td>{{ $subtotal>0 ? number_format($line+$allocated,2) : 'Pending' }}</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="3">Total ({{ $currency }})</th><th>{{ number_format($subtotal,2) }}</th><th>{{ $subtotal>0 ? number_format($expenses,2) : 'Pending' }}</th><th>{{ $subtotal>0 ? number_format($landed,2) : 'Pending' }}</th></tr></tfoot></table></div>
<p class="muted">Estimated expenses are allocated proportionally by item value. Final allocation will be rounded and posted during receiving. Zero-value orders cannot be allocated.</p></div>
<div class="panel"><h2>Purchase Expenses</h2>
@if($order->status==='draft')
<form method="POST" action="{{ route('purchases.orders.expenses.store',$order) }}" class="po-form">@csrf
<label>Expense category<select name="category" required>@foreach(['transport'=>'Transportation','loading'=>'Loading / unloading','customs'=>'Customs','insurance'=>'Insurance','freight'=>'Freight / shipment','handling'=>'Handling','other'=>'Other'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
<label>Description<input name="description" maxlength="255" placeholder="Optional details"></label>
<label>Amount ({{ $currency }})<input type="number" name="amount" required min="0.0001" step="0.0001"></label>
<label>Date<input type="date" name="expense_date" required value="{{ now()->toDateString() }}"></label>
<button type="submit">Add Expense</button>
</form>
@endif
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount ({{ $currency }})</th></tr></thead><tbody>@forelse($order->expenses as $expense)<tr><td>{{ $expense->expense_date }}</td><td>{{ ucfirst($expense->category) }}</td><td>{{ $expense->description }}</td><td>{{ number_format((float)$expense->amount,2) }}</td></tr>@empty<tr><td colspan="4">No purchase expenses recorded.</td></tr>@endforelse</tbody></table></div></div>
<div class="panel"><h2>Supplier Advances</h2>
@if($order->status==='draft')
<form method="POST" action="{{ route('purchases.orders.advances.store',$order) }}" class="po-form">@csrf
<label>Payment date<input type="date" name="payment_date" required value="{{ now()->toDateString() }}"></label>
<label>Amount ({{ $currency }})<input type="number" name="amount" required min="0.0001" step="0.0001"></label>
<label>Reference<input name="reference" maxlength="100" placeholder="Receipt / transfer reference"></label>
<label>Notes<input name="notes" maxlength="2000"></label>
<button type="submit">Record Advance</button>
</form>
@endif
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Reference</th><th>Notes</th><th>Amount ({{ $currency }})</th></tr></thead><tbody>@forelse($order->advances as $advance)<tr><td>{{ $advance->payment_date }}</td><td>{{ $advance->reference }}</td><td>{{ $advance->notes }}</td><td>{{ number_format((float)$advance->amount,2) }}</td></tr>@empty<tr><td colspan="4">No supplier advances recorded.</td></tr>@endforelse</tbody></table></div>
<div class="po-notice">Recorded advances are purchase-level tracking entries only. They do not transfer money or create accounting postings. Supplier balance excludes third-party expenses and is not a verified payable balance. All values are in the order currency.</div></div>
@endsection