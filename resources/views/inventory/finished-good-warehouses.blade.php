@extends('layouts.kanozay')
@section('content')
<style>
.fw-panel{border:1px solid #dce8f2;border-radius:12px;padding:20px;margin:14px 0;background:white}
.fw-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.fw-grid label{display:grid;gap:6px;font-size:12px;color:#3c5f7f}
.fw-grid input,.fw-grid select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #d6e3ee;border-radius:8px}
.fw-wrap{overflow:auto}.fw-panel table{min-width:900px;width:100%;font-size:12px}
.fw-panel td,.fw-panel th{padding:11px}
.fw-info{background:#f5f8fc;border-radius:8px;padding:12px;color:#4d6380;font-size:12px}
@media(max-width:860px){.fw-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Finished Goods Warehouse Stock</h1><p>Physical warehouse allocations by released production batch and package quantity</p></div>
<a class="btn btn-light" href="{{ route('inventory.finished-good-lots.index') }}">Lot expiry & recall controls</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<p class="fw-info">Historical finished goods have no physical warehouse until allocated. A warehouse transfer changes only physical location, not production output, customer receivables, stock valuation or company-wide on-hand quantity. New QC releases and sales invoices support direct warehouse selection. During supplier/customer returns, only reviewed saleable stock can reenter availability.</p>
@canany(['inventory.update','administration.update'])
<section class="fw-panel"><h2>Allocate or transfer a released batch</h2>
<form method="POST" action="{{ route('inventory.finished-good-warehouses.transfer') }}" class="fw-grid">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Released production batch<select name="production_batch_id" required><option value="">Choose batch</option>
@foreach($stock as $record)<option value="{{ $record['batch']->id }}">{{ $record['batch']->batch_number }} · {{ $record['batch']->finishedGood?->sku }} ({{ $record['available'] }} available)</option>@endforeach
</select></label>
<label>Physical source warehouse<select name="source_warehouse_id"><option value="">Unassigned legacy stock</option>
@foreach($warehouses as $wh)<option value="{{ $wh->id }}">{{ $wh->code }} · {{ $wh->name }}</option>@endforeach</select></label>
<label>Destination warehouse<select name="destination_warehouse_id" required><option value="">Choose destination</option>
@foreach($warehouses as $wh)<option value="{{ $wh->id }}">{{ $wh->code }} · {{ $wh->name }}</option>@endforeach</select></label>
<label>Packages<input type="number" name="quantity_packages" min="1" max="2147483647" required></label>
<label>Movement date<input type="date" name="occurred_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></label>
<label>Physical movement reference / reason<input name="reason" minlength="5" maxlength="160" required></label>
<button class="btn" type="submit" onclick="return confirm('Move this batch without changing global finished goods totals?')">Post warehouse allocation</button></form></section>
@endcanany
<section class="fw-panel"><h2>Released batch warehouse reconciliation</h2>
<div class="fw-wrap"><table><thead><tr><th>Batch</th><th>Finished good</th><th>Global available</th><th>Assigned to warehouses</th><th>Unassigned</th><th>Physical warehouse quantities</th><th>Health</th></tr></thead><tbody>
@forelse($stock as $record)<tr><td>{{ $record['batch']->batch_number }}</td><td>{{ $record['batch']->finishedGood?->name }}</td><td>{{ $record['available'] }}</td><td>{{ $record['assigned'] }}</td><td>{{ $record['unassigned'] }}</td>
<td>@foreach($warehouses as $wh)@if(isset($record['warehouse'][$wh->id]))
<div>{{ $wh->code }}: {{ $record['warehouse'][$wh->id]->quantity_packages }} packages</div>
@endif @endforeach</td>
<td>@if($record['unassigned']<0)<strong style="color:#a93131">Overallocated: reconcile before dispatch</strong>@else <span style="color:#177b57">Within global balance</span>@endif</td></tr>
@empty<tr><td colspan="7">No released finished goods batches.</td></tr>@endforelse
</tbody></table></div></section>
<section class="fw-panel"><h2>Movement history</h2><div class="fw-wrap"><table><thead><tr><th>Batch</th><th>When</th><th>Movement</th><th>Source</th><th>Destination</th><th>Packages</th><th>Reference</th></tr></thead><tbody>
@forelse($moves as $m)<tr><td>{{ $m->batch_number }}</td><td>{{ $m->occurred_on }}</td><td>{{ ucwords(str_replace('_',' ',$m->type)) }}</td><td>{{ $m->source_name?:'Unassigned / production' }}</td><td>{{ $m->destination_name?:'Dispatched' }}</td><td>{{ $m->quantity_packages }}</td><td>{{ $m->reference }}</td></tr>
@empty<tr><td colspan="7">No finished goods movements recorded.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
