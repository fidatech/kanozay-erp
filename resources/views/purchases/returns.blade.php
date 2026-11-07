@extends('layouts.kanozay')
@section('content')
<style>
.pr-panel{padding:22px;border:1px solid #dde6f1;border-radius:12px;background:white;margin-bottom:15px}
.pr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.pr-grid label{display:grid;gap:6px;font-size:12px;color:#43627d;font-weight:700}
.pr-grid input,.pr-grid textarea,.pr-grid select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #d7e4f0;border-radius:8px}
.pr-wrap{overflow:auto;border:1px solid #dfe8f2;border-radius:10px}
.pr-panel table{min-width:810px;margin:0;font-size:12px}
.pr-panel th,.pr-panel td{padding:12px}
.pr-note{border-radius:8px;background:#fff8e6;color:#785625;padding:12px;font-size:12px;line-height:1.6}
@media(max-width:850px){.pr-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Purchase Returns</h1><p>Supplier returns reconciled with immutable goods receipts and physical stock</p></div><a class="btn btn-light" href="{{ route('workspace.page',['purchases','orders']) }}">Purchase orders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<div class="pr-panel">
<h2>Select the original goods receipt</h2>
<form method="GET" action="{{ route('workspace.page',['purchases','purchase-returns']) }}" style="display:flex;gap:10px;flex-wrap:wrap">
<select name="receipt_id" required style="min-width:300px"><option value="">Choose posted GRN</option>
@foreach($receipts as $r)<option value="{{ $r->id }}" @selected($receipt?->id===$r->id)>{{ $r->number }} · {{ $r->purchaseOrder?->supplier?->name }}</option>@endforeach
</select><button type="submit" class="btn">Load receipt</button></form>
</div>
@if($receipt)
@canany(['purchases.update','administration.update'])
<section class="pr-panel">
<h2>Return items from {{ $receipt->number }}</h2>
<p class="pr-note">Posting decreases physical inventory and consumes FIFO cost layers. It does not automatically change supplier payable balances, apply a debit note or confirm a supplier credit. Reconcile those with Finance and the supplier.</p>
<form method="POST" action="{{ route('purchases.returns.store') }}" class="pr-grid">@csrf
<input type="hidden" name="goods_receipt_id" value="{{ $receipt->id }}">
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Return date<input type="date" name="returned_on" value="{{ old('returned_on',now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></label>
<label>Warehouse stock source<select name="company_warehouse_id">
<option value="">Unassigned stock</option>@foreach($warehouses as $wh)
<option value="{{ $wh->id }}" @selected(old('company_warehouse_id',$receipt->company_warehouse_id)==$wh->id)>{{ $wh->code }} · {{ $wh->name }}</option>
@endforeach</select></label>
<label>Supplier acknowledgment<input name="supplier_reference" value="{{ old('supplier_reference') }}" maxlength="120"></label>
<label style="grid-column:1/-1">Return reason<textarea name="reason" minlength="10" maxlength="2500" required>{{ old('reason') }}</textarea></label>
<div style="grid-column:1/-1" class="pr-wrap"><table><thead><tr><th>Material</th><th>Originally received</th><th>Previously returned</th><th>Available to return</th><th>Return now</th></tr></thead><tbody>
@foreach($receipt->lines as $line)
@php($available=$remaining[$line->id]??'0')
<tr><td>{{ $line->material?->sku }} · {{ $line->material?->name }}</td><td>{{ $line->quantity }} {{ $line->unit }}</td>
<td>{{ (string)\Brick\Math\BigDecimal::of((string)$line->quantity)->minus($available)->toScale(6) }}</td>
<td>{{ $available }}</td><td>
@if(\Brick\Math\BigDecimal::of($available)->isPositive())
<input name="quantities[{{ $line->id }}]" inputmode="decimal" value="{{ old('quantities.'.$line->id) }}" placeholder="0">
@else<span>Fully returned</span>@endif</td></tr>@endforeach
</tbody></table></div>
<button type="submit" class="btn" onclick="return confirm('Post this supplier return and reduce physical stock?')">Post supplier return</button>
</form></section>
@endcanany
@endif
<section class="pr-panel"><h2>Posted purchase returns</h2><div class="pr-wrap"><table><thead><tr><th>Return number</th><th>Receipt</th><th>Supplier</th><th>Return date</th><th>Financial status</th><th>Details</th></tr></thead><tbody>
@forelse($returns as $return)
<tr><td>{{ $return->number }}</td><td>{{ $return->receipt?->number }}</td><td>{{ $return->order?->supplier?->name }}</td><td>{{ $return->returned_on?->toDateString() }}</td>
<td>Supplier credit pending</td><td><a href="{{ route('purchases.returns.show',$return) }}">View return</a></td></tr>
@empty<tr><td colspan="6">No purchase returns have been posted.</td></tr>@endforelse
</tbody></table></div>{{ $returns->links() }}</section>
@endsection
