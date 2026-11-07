@extends('layouts.kanozay')
@section('content')
<style>
.req-panel{border:1px solid #dfe9f1;background:#fff;padding:20px;border-radius:12px;margin-bottom:16px}
.req-panel h2{margin:0 0 14px}
.req-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.req-grid label{display:grid;gap:5px;color:#3f6080;font-size:12px;font-weight:700}
.req-grid input,.req-grid textarea,.req-grid select{box-sizing:border-box;width:100%;padding:10px;min-height:42px;border:1px solid #d8e5f1;border-radius:7px}
.req-grid .wide{grid-column:1/-1}
.req-wrap{overflow:auto;border:1px solid #dce6ee;border-radius:8px}
.req-panel table{min-width:820px;font-size:12px;margin:0}.req-panel th,.req-panel td{padding:11px}
.req-panel th{background:#f2f6fb}
.req-inline{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.req-help{font-size:12px;color:#657f98;line-height:1.65}
.req-review{border:1px solid #d8e2ef;border-radius:9px;padding:12px;margin-top:14px}
@media(max-width:860px){.req-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $pageTitle }} · Procurement request workflow</h1><p>Material requirements, reorder planning, independent approval and draft purchase orders.</p></div><a class="btn btn-light" href="{{ route('purchases.orders.index') }}">Purchase orders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
@canany(['inventory.update','administration.update'])
<section class="req-panel"><h2>Configure replenishment rules</h2>
<form class="req-grid" method="POST" action="{{ route('procurement.rules.store') }}">@csrf
<label>Material<select name="inventory_material_id" required><option value="">Select active material</option>@foreach($materials as $material)<option value="{{ $material->id }}">{{ $material->sku }} · {{ $material->name }}</option>@endforeach</select></label>
<label>Reorder minimum<input name="minimum_quantity" inputmode="decimal" required placeholder="0"></label>
<label>Target stock quantity<input name="target_quantity" inputmode="decimal" required></label>
<label>Supplier preference<select name="supplier_id"><option value="">No supplier preference</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
<label>Lead days<input type="number" min="0" max="3650" value="7" name="lead_days" required></label>
<div><button class="btn" type="submit">Save rule</button></div>
</form></section>@endcanany
<section class="req-panel"><h2>Current stock and reorder suggestions</h2><div class="req-wrap"><table>
<thead><tr><th>Material</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Minimum</th><th>Target</th><th>Suggested order</th></tr></thead><tbody>
@forelse($suggestions as $s)<tr>
<td>{{ $s['material']->sku }} · {{ $s['material']->name }}</td>
<td>{{ $s['material']->on_hand_quantity }}</td><td>{{ $s['material']->reserved_quantity }}</td>
<td>{{ $s['available'] }}</td><td>{{ $s['rule']?->minimum_quantity??'Not set' }}</td><td>{{ $s['rule']?->target_quantity??'Not set' }}</td>
<td><strong>{{ $s['suggested'] }}</strong></td></tr>
@empty<tr><td colspan="7">Create inventory materials before configuring reorder rules.</td></tr>@endforelse
</tbody></table></div><p class="req-help">Suggested quantities are computed from global available stock and user-supplied thresholds. A suggestion is not a purchase order or an inventory reservation.</p></section>
@canany(['inventory.create','purchases.create','administration.update'])
<section class="req-panel"><h2>Submit material requisition</h2>
<form method="POST" action="{{ route('procurement.requests.store') }}" class="req-grid">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Request date<input type="date" name="requested_on" max="{{ now()->toDateString() }}" value="{{ old('requested_on',now()->toDateString()) }}" required></label>
<label>Needed by<input type="date" name="required_on"></label>
<label class="wide">Purpose<textarea name="notes" maxlength="3000" rows="2"></textarea></label>
<label>Material<select name="lines[0][inventory_material_id]" required><option value="">Select material</option>@foreach($materials as $m)<option value="{{ $m->id }}">{{ $m->sku }} · {{ $m->name }} ({{ $m->unit }})</option>@endforeach</select></label>
<label>Requested quantity<input name="lines[0][quantity]" inputmode="decimal" required></label>
<label>Line comment<input name="lines[0][description]" maxlength="255"></label>
<div><button class="btn" type="submit">Submit for approval</button></div></form>
<p class="req-help">For multiple materials in a single request, the API accepts indexed line inputs. Requests remain immutable after submission and cannot create commitments before approval.</p></section>@endcanany
<section class="req-panel"><h2>Procurement request register</h2>
<div class="req-wrap"><table><thead><tr><th>Reference</th><th>Status</th><th>Requested by</th><th>Materials</th><th>Dates</th><th>Purchase order</th></tr></thead><tbody>
@forelse($requests as $req)
<tr><td><strong>{{ $req->number }}</strong></td><td>{{ ucfirst($req->status) }}</td><td>{{ $req->creator?->name }}</td>
<td>@foreach($req->lines as $line)<div>{{ $line->material?->sku }}: {{ $line->quantity }} {{ $line->unit_snapshot }}</div>@endforeach</td>
<td>{{ $req->requested_on?->format('Y-m-d') }}<br><small>{{ $req->required_on?->format('Y-m-d')?:'No due date' }}</small></td>
<td>@if($req->order)<a href="{{ route('purchases.orders.show',$req->order) }}">{{ $req->order->number }}</a>@else — @endif</td></tr>
<tr><td colspan="6">
@if($req->status==='submitted')
@canany(['purchases.update','administration.update'])
<form method="POST" action="{{ route('procurement.requests.decide',$req) }}" class="req-inline">@csrf
<input name="decision_note" maxlength="1000" placeholder="Reason / approval note" style="min-width:180px">
<button name="decision" value="approved" class="btn btn-secondary">Approve</button>
<button name="decision" value="rejected" class="btn btn-light">Reject</button></form>
@endcanany
@elseif($req->status==='approved')
@canany(['purchases.create','administration.update'])
<details class="req-review"><summary>Create draft purchase order with confirmed supplier pricing</summary>
<form method="POST" action="{{ route('procurement.requests.convert',$req) }}" class="req-grid" style="margin-top:12px">@csrf
<label>Supplier<select name="supplier_id" required><option value="">Choose supplier</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<label>Currency<select name="currency_id" required><option value="">Choose currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></label>
<label>Source<select name="source"><option value="local">Local</option><option value="foreign">Foreign</option></select></label>
<label>PO date<input name="order_date" type="date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Expected date<input name="expected_date" type="date"></label>
@foreach($req->lines as $line)
<label>Unit price · {{ $line->material?->sku }} ({{ $line->unit_snapshot }})<input name="unit_prices[{{ $line->id }}]" inputmode="decimal" required placeholder="0.0000"></label>
@endforeach
<label class="wide">Additional PO notes<textarea name="notes" maxlength="2500"></textarea></label>
<button type="submit" class="btn">Create draft PO</button></form>
</details>
@endcanany
@elseif($req->status==='rejected')
<p class="req-help">Rejected: {{ $req->decision_note }}</p>
@endif
</td></tr>
@empty<tr><td colspan="6" style="padding:28px;text-align:center">No material requests submitted.</td></tr>@endforelse
</tbody></table></div>{{ $requests->links() }}</section>
@endsection
