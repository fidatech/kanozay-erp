@extends('layouts.kanozay')
@section('content')
<style>
.cp-panel{padding:20px;border:1px solid #dbe6f2;border-radius:12px;background:white;margin-bottom:15px}
.cp-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.cp-form label{display:grid;gap:6px;font-size:12px;color:#45607b}
.cp-form input,.cp-form select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #d4e1ed;border-radius:8px}
.cp-panel .cp-wrap{overflow:auto}.cp-panel table{min-width:730px;font-size:12px}
@media(max-width:850px){.cp-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $page==='pricing'?'Customer Price Lists':'Customer Credit Limits' }}</h1><p>Sales / Financially reviewed customer terms per currency</p></div><a class="btn btn-light" href="{{ route('sales.invoices.index') }}">Sales invoices</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@if($page==='customer-credit-limits')
<div class="cp-panel"><h2>Approved invoice credit ceilings</h2>
<p class="muted">An enabled limit stops new invoices when outstanding receivables and the proposed invoice exceed the approved amount in this currency. Disabled or missing rules do not restrict sales.</p>
@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('sales.credit-limits.store') }}" class="cp-form">@csrf
<label>Customer<select name="trade_party_id" required><option value="">Select customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
<label>Currency<select name="currency_id" required><option value="">Select currency</option>@foreach($currencies as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach</select></label>
<label>Maximum open credit<input name="limit_amount" inputmode="decimal" required></label>
<label>Status<select name="is_active"><option value="1">Enforce</option><option value="0">Disabled</option></select></label>
<label>Approval reference<input name="approval_reference" required minlength="3" maxlength="180"></label>
<button class="btn" type="submit">Save credit limit</button>
</form>@endcanany</div>
<div class="cp-panel"><h2>Customer credit policy register</h2><div class="cp-wrap"><table><thead><tr><th>Customer</th><th>Currency</th><th>Limit</th><th>Enforced</th><th>Approval</th></tr></thead><tbody>
@forelse($limits as $l)<tr><td>{{ $l->customer_name }}</td><td>{{ $l->currency_code }}</td><td>{{ $l->limit_amount }}</td><td>{{ $l->is_active?'Yes':'No' }}</td><td>{{ $l->approval_reference }}</td></tr>
@empty<tr><td colspan="5">No approved credit policies recorded.</td></tr>@endforelse
</tbody></table></div></div>
@else
<div class="cp-panel"><h2>Dated customer selling-price references</h2>
<p class="muted">Price references are informational. Invoice amounts are confirmed explicitly before posting; no unapproved discount or tax policy is applied.</p>
@canany(['sales.update','administration.update'])
<form class="cp-form" method="POST" action="{{ route('sales.prices.store') }}">@csrf
<label>Customer<select name="trade_party_id" required><option value="">Select customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
<label>Finished product<select name="finished_good_id" required><option value="">Select product</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->sku }} · {{ $p->name }}</option>@endforeach</select></label>
<label>Currency<select name="currency_id" required><option value="">Select currency</option>@foreach($currencies as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach</select></label>
<label>Unit price<input name="unit_price" inputmode="decimal" required></label>
<label>Effective on<input type="date" name="effective_on" value="{{ now()->toDateString() }}" required></label>
<label>Expires on<input type="date" name="expires_on"></label>
<label>Status<select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></label>
<button class="btn">Save price</button>
</form>@endcanany</div>
<div class="cp-panel"><h2>Price reference register</h2><div class="cp-wrap"><table><thead><tr><th>Customer</th><th>Product</th><th>Currency</th><th>Price</th><th>Effective</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
@forelse($prices as $p)<tr><td>{{ $p->customer_name }}</td><td>{{ $p->product_sku }}</td><td>{{ $p->currency_code }}</td><td>{{ $p->unit_price }}</td><td>{{ $p->effective_on }}</td><td>{{ $p->expires_on?:'—' }}</td><td>{{ $p->is_active?'Active':'Inactive' }}</td></tr>
@empty<tr><td colspan="7">No price references.</td></tr>@endforelse
</tbody></table></div></div>
@endif
@endsection
