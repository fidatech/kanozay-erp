@extends('layouts.kanozay')
@section('content')
<style>
.wh-panel{background:white;border:1px solid #dfe8f3;border-radius:12px;padding:21px;margin-bottom:16px}
.wh-panel table{min-width:800px;margin:0;font-size:12px}
.wh-panel td,.wh-panel th{padding:12px}
.wh-panel th{background:#f0f6fc}
.wh-form{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px!important;align-items:end!important}
.wh-form label{display:grid;gap:7px;font-size:12px;color:#375575;font-weight:700}
.wh-form input,.wh-form select{height:43px;min-width:0;box-sizing:border-box;width:100%;padding:10px;border:1px solid #d8e3ef;border-radius:8px}
.wh-warning{padding:12px;border:1px solid #f2d9b5;border-radius:9px;color:#775019;background:#fffbf2;font-size:12px;line-height:1.6}
.wh-table{overflow:auto;border:1px solid #dfe8f3;border-radius:10px}
@media(max-width:1000px){.wh-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.wh-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Warehouse stock allocation & transfers</h1><p>Raw materials / Warehouse-level allocation without duplicate inventory postings.</p></div><a class="btn btn-light" href="{{ route('admin.company.edit') }}">Manage warehouses</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ $errors->first() }}</p>@endif
<section class="wh-panel"><h2>Company-wide stock reconciliation</h2>
<p class="wh-warning">Existing inventory was recorded without warehouse locations. Assign it deliberately below. Allocating and transferring stock here does not create or remove company-wide stock, valuation or supplier journals. New receipts and production issues still require warehouse-level integration, so unassigned quantities may change after those transactions. Never treat unassigned quantities as physical stock at a specific site.</p>
<div class="wh-table"><table><thead><tr><th>Material</th><th>Global on-hand</th><th>Global reserved</th><th>Warehouse allocations</th><th>Unassigned</th><th>Reconcile</th></tr></thead><tbody>
@forelse($data as $row)
<tr><td><strong>{{ $row['material']->sku }}</strong><br>{{ $row['material']->name }}</td><td>{{ $row['material']->on_hand_quantity }} {{ $row['material']->unit }}</td><td>{{ $row['material']->reserved_quantity }}</td><td>@foreach($warehouses as $warehouse)@if(isset($row['warehouses'][$warehouse->id]))<div>{{ $warehouse->code }}: {{ $row['warehouses'][$warehouse->id] }}</div>@endif @endforeach</td>
<td>{{ $row['unallocated'] }}</td><td>@if($row['reconcile']==='mismatch')<span style="color:#b62e2e">Overallocated</span>@else <span style="color:#19805a">Within balance</span>@endif</td></tr>
@empty<tr><td colspan="6">No active materials.</td></tr>@endforelse
</tbody></table></div></section>
@canany(['inventory.update','administration.update'])
<section class="wh-panel"><h2>Allocate or transfer raw material</h2>
<p class="wh-warning">Initial allocation assigns stock from the unassigned pool to a chosen warehouse. A transfer moves already allocated stock between warehouses, but is blocked when company-wide production reservations exist for the material.</p>
<form class="wh-form" method="POST" action="{{ route('inventory.warehouse-stock.store') }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Operation<select name="type" required><option value="initial_allocation" @selected(old('type')==='initial_allocation')>Allocate existing stock</option><option value="transfer" @selected(old('type')==='transfer')>Transfer between warehouses</option></select></label>
<label>Material<select name="inventory_material_id" required><option value="">Select raw material</option>
@foreach($data as $row)<option value="{{ $row['material']->id }}" @selected(old('inventory_material_id')==$row['material']->id)>{{ $row['material']->sku }} - {{ $row['material']->name }}</option>@endforeach
</select></label>
<label>Source warehouse (transfer only)<select name="source_warehouse_id"><option value="">Unassigned pool / none</option>
@foreach($warehouses as $wh)<option value="{{ $wh->id }}" @selected(old('source_warehouse_id')==$wh->id)>{{ $wh->code }} - {{ $wh->name }}</option>@endforeach
</select></label>
<label>Destination warehouse<select name="destination_warehouse_id" required><option value="">Choose destination</option>
@foreach($warehouses as $wh)<option value="{{ $wh->id }}" @selected(old('destination_warehouse_id')==$wh->id)>{{ $wh->code }} - {{ $wh->name }}</option>@endforeach
</select></label>
<label>Quantity<input name="quantity" type="text" inputmode="decimal" placeholder="0.000000" required value="{{ old('quantity') }}"></label>
<label>Effective date<input name="occurred_on" type="date" max="{{ now()->toDateString() }}" required value="{{ old('occurred_on',now()->toDateString()) }}"></label>
<label style="grid-column:span 2">Reason<input name="reason" maxlength="500" minlength="5" required value="{{ old('reason') }}" placeholder="Inventory assignment / transfer reference"></label>
<div><button class="btn" type="submit" onclick="return confirm('Record this warehouse-only movement? Global stock stays unchanged.')">Post allocation or transfer</button></div>
</form></section>
@endcanany
<section class="wh-panel"><h2>Warehouse movement audit</h2>
<div class="wh-table"><table><thead><tr><th>Recorded</th><th>Material</th><th>Operation</th><th>Source</th><th>Destination</th><th>Quantity</th><th>Reason</th></tr></thead><tbody>
@forelse($recent as $row)<tr><td>{{ $row->occurred_on }}</td><td>{{ $row->sku }}</td><td>{{ ucwords(str_replace('_',' ',$row->type)) }}</td><td>{{ $row->source?:'Unassigned' }}</td><td>{{ $row->destination }}</td><td>{{ $row->quantity }}</td><td>{{ $row->reason }}</td></tr>
@empty<tr><td colspan="7">No warehouse allocations or transfers recorded yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
