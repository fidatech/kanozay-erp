@extends('layouts.kanozay')
@section('content')
<style>
.dn-form-panel{padding:20px;border-radius:12px}.dn-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}
.dn-form-grid label{display:grid;gap:5px;font-size:12px;font-weight:700;color:#385575}
.dn-form-grid input,.dn-form-grid textarea{padding:11px;border:1px solid #d4e1ee;border-radius:8px;width:100%;min-height:42px;box-sizing:border-box}
.dn-form-panel .dn-table{max-width:100%;overflow:auto;border:1px solid #e0eaf5;border-radius:8px}
.dn-form-panel table{min-width:680px;font-size:12px}.dn-form-panel input[name^="quantities"]{width:130px;text-align:right}
@media(max-width:750px){.dn-form-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Issue delivery note</h1><p>Record a physical shipment against an existing posted invoice</p></div><a class="btn btn-light" href="{{ route('workspace.page',['sales','delivery-notes']) }}">All notes</a></div>
@if($errors->any())<div class="panel" style="color:#ad3329">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="panel dn-form-panel"><h2>Choose an invoice</h2>
<form method="GET" action="{{ route('sales.delivery-notes.create') }}" style="display:flex;gap:9px;flex-wrap:wrap">
<select name="invoice_id" required style="min-width:310px;max-width:100%">
<option value="">Select a posted invoice...</option>
@foreach($invoices as $item)<option value="{{ $item->id }}" @selected($invoice?->id===$item->id)>{{ $item->number }} — {{ $item->customer?->name }}</option>@endforeach
</select><button class="btn">Load invoice</button>
</form></section>
@if($invoice)
<section class="panel dn-form-panel">
<h2>Invoice {{ $invoice->number }} / {{ $invoice->customer?->name }}</h2>
<p class="muted">Available quantities subtract previous non-cancelled delivery notes and already credited customer returns. Issuing this document creates no extra stock issue, sales journal or customer debt.</p>
<form method="POST" action="{{ route('sales.delivery-notes.store') }}" style="display:block">@csrf
<input type="hidden" name="sales_invoice_id" value="{{ $invoice->id }}">
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<div class="dn-form-grid">
<label>Delivery date<input type="date" name="delivery_date" required value="{{ old('delivery_date',now()->toDateString()) }}" max="{{ now()->toDateString() }}"></label>
<label>Vehicle number<input name="vehicle_number" maxlength="100" value="{{ old('vehicle_number') }}"></label>
<label>Driver name<input name="driver_name" maxlength="150" value="{{ old('driver_name') }}"></label>
<label>Delivery address<input name="delivery_address" maxlength="500" value="{{ old('delivery_address',$invoice->customer?->address) }}"></label>
<label>Customer reference<input name="reference" maxlength="100" value="{{ old('reference') }}"></label>
<label>Notes<input name="notes" maxlength="2000" value="{{ old('notes') }}"></label>
</div>
<div class="dn-table" style="margin-top:18px"><table><thead><tr><th>Finished product</th><th>Batch</th><th>Invoiced</th><th>Documented or credited</th><th>Available to note</th><th>Deliver now</th></tr></thead><tbody>
@foreach($invoice->lines as $line)
@php($available=$remaining[$line->id]??0)
<tr><td>{{ $line->finishedGood?->name }}</td><td>{{ $line->batch?->batch_number }}</td><td>{{ $line->quantity_packages }}</td>
<td>{{ $line->quantity_packages-$available }}</td><td>{{ $available }}</td><td>
@if($available>0)<input type="text" name="quantities[{{ $line->id }}]" inputmode="numeric" min="0" max="{{ $available }}" step="1" value="{{ old('quantities.'.$line->id) }}" placeholder="0">@else <span class="muted">Already documented or credited</span>@endif
</td></tr>
@endforeach
</tbody></table></div>
<button class="btn" style="margin-top:15px" type="submit" onclick="return confirm('Issue the delivery note? Invoice stock has already been dispatched.')">Issue delivery note</button>
</form>
</section>
@endif
@endsection
