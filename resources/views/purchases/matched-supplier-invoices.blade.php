@extends('layouts.kanozay')
@section('content')
<style>
.si-panel{padding:20px;border:1px solid #dae6f2;border-radius:12px;background:white;margin:15px 0}
.si-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.si-grid label{display:grid;gap:6px;font-size:12px;color:#375574;font-weight:700}
.si-grid input,.si-grid select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #d1e1ef;border-radius:7px}
.si-wrap{overflow:auto}.si-panel table{min-width:760px;font-size:12px}.si-panel td,.si-panel th{padding:10px}
.si-note{font-size:12px;color:#8b611e;background:#fff8e8;padding:12px;border-radius:8px;line-height:1.7}
@media(max-width:900px){.si-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Goods Received Notes — Supplier Invoice Matching</h1><p>Three-way PO, GRN and supplier invoice matching</p></div><a class="btn btn-light" href="{{ route('finance.documents.index',['type'=>'payable']) }}">Supplier payables</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<section class="si-panel"><h2>Select a posted goods receipt</h2>
<form method="GET" action="{{ route('workspace.page',['purchases','goods-receipts']) }}" style="display:flex;flex-wrap:wrap;gap:10px">
<select name="receipt_id" required style="min-width:300px"><option value="">Choose posted GRN</option>@foreach($receipts as $r)<option value="{{ $r->id }}" @selected($receipt?->id===$r->id)>{{ $r->number }} · {{ $r->purchaseOrder?->supplier?->name }}</option>@endforeach</select>
<button class="btn" type="submit">Review GRN</button></form>
<p class="si-note">Posting recognizes a verified supplier liability; it does not receive stock again. Unit prices are frozen at the GRN and must match the purchase order. Shipping, tax, discounts, FX and advances require separate reconciliation.</p>
</section>
@if($receipt)
@canany(['administration.update','purchases.update'])
<section class="si-panel"><h2>Match supplier invoice against {{ $receipt->number }}</h2>
<p>Supplier: {{ $receipt->purchaseOrder?->supplier?->name }} · PO {{ $receipt->purchaseOrder?->number }} · Currency {{ $receipt->purchaseOrder?->currency?->code }}</p>
<form method="POST" class="si-grid" action="{{ route('purchases.matched-supplier-invoices.store') }}">@csrf
<input type="hidden" name="goods_receipt_id" value="{{ $receipt->id }}">
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Supplier invoice no.<input name="supplier_invoice_number" minlength="2" maxlength="100" required></label>
<label>Invoice date<input name="invoice_date" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></label>
<label>Due date<input name="due_date" type="date" value="{{ now()->addMonth()->toDateString() }}" required></label>
<label>Approved inventory asset<select name="inventory_ledger_id" required><option value="">Select account</option>@foreach($ledgers as $l)<option value="{{ $l->id }}">{{ $l->code }} · {{ $l->name }}</option>@endforeach</select></label>
<label>Verified invoice total<input name="invoice_total" inputmode="decimal" required></label>
<div class="si-wrap" style="grid-column:1/-1"><table><thead><tr><th>Material</th><th>Received</th><th>Unbilled and not returned</th><th>Unit cost snapshot</th><th>Bill quantity</th></tr></thead><tbody>
@foreach($receipt->lines as $l)<tr><td>{{ $l->material?->name }}</td><td>{{ $l->quantity }} {{ $l->unit }}</td><td>{{ $remaining[$l->id]??'0' }}</td><td>{{ $l->unit_price_snapshot??'Missing' }}</td>
<td><input name="quantities[{{ $l->id }}]" inputmode="decimal" aria-label="Bill quantity for {{ $l->material?->name }}" placeholder="0"></td></tr>@endforeach
</tbody></table></div>
<div><button class="btn" type="submit" onclick="return confirm('Post source-matched supplier invoice to payables?')">Post matched payable</button></div>
</form></section>@endcanany
@endif
<section class="si-panel"><h2>Matched supplier invoices</h2><div class="si-wrap"><table><thead><tr><th>Our number</th><th>Supplier invoice</th><th>Supplier</th><th>GRN</th><th>Currency</th><th>Payable amount</th><th>GL document</th></tr></thead><tbody>
@forelse($invoices as $i)<tr><td><a href="{{ route('purchases.matched-supplier-invoices.show',$i) }}">{{ $i->number }}</a></td><td>{{ $i->supplier_invoice_number }}</td><td>{{ $i->supplier?->name }}</td><td>{{ $i->receipt?->number }}</td><td>{{ $i->currency?->code }}</td><td>{{ $i->total }}</td><td>{{ $i->payable?->number }}</td></tr>
@empty<tr><td colspan="7">No matched supplier invoices posted.</td></tr>@endforelse
</tbody></table></div>{{ $invoices->links() }}</section>
@endsection
