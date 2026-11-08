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
<div class="heading"><div><h1>Warehouse stock allocation & transfers</h1><p>Raw materials / Warehouse FIFO lot provenance and transfers without duplicate inventory postings.</p></div><a class="btn btn-light" href="{{ route('admin.company.edit') }}">Manage warehouses</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ $errors->first() }}</p>@endif
<section class="wh-panel"><h2>Company-wide stock reconciliation</h2>
<p class="wh-warning">Warehouse quantities and FIFO lot positions are tracked separately. New receipts and adjustments create lot positions automatically. Older lots require documented location reconciliation before they can be issued or transferred. Every transfer preserves the original lot, purchase cost and QC status without changing total company stock or posting another supplier invoice.</p>
<div class="wh-table"><table><thead><tr><th>Material</th><th>Global on-hand</th><th>Global reserved</th><th>Warehouse physical / traced lots</th><th>Unassigned physical / traced</th><th>Unpositioned</th><th>Lot reconciliation</th></tr></thead><tbody>
@forelse($data as $row)
<tr><td><strong>{{ $row['material']->sku }}</strong><br>{{ $row['material']->name }}</td><td>{{ $row['material']->on_hand_quantity }} {{ $row['material']->unit }}</td><td>{{ $row['material']->reserved_quantity }}</td><td>@foreach($warehouses as $warehouse)@if(isset($row['warehouses'][$warehouse->id]))<div>{{ $warehouse->code }}: {{ $row['warehouses'][$warehouse->id] }} / {{ $row['verified_lots']['wh:'.$warehouse->id]??'0.000000' }}</div>@endif @endforeach</td>
<td>{{ $row['unallocated'] }} / {{ $row['verified_lots']['unassigned']??'0.000000' }}</td>
<td>{{ $row['unpositioned_physical'] }}</td>
<td>@if($row['reconcile']==='mismatch')<span style="color:#b62e2e">Location mismatch</span>
@elseif($row['reconcile']==='located')<span style="color:#19805a">All lots located</span>
@else <span style="color:#966014">Provenance pending</span>@endif</td></tr>
@empty<tr><td colspan="7">No active materials.</td></tr>@endforelse
</tbody></table></div></section>
@canany(['inventory.update','administration.update'])
<section class="wh-panel"><h2>Allocate or transfer raw material</h2>
<p class="wh-warning">Initial allocation and transfer now preserve the exact FIFO lots and their costs. Unverified historical lots, QC-held materials and globally reserved materials cannot be transferred until the relevant controls are cleared. Use the reconciliation form below for documented historical lot attribution.</p>
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
@if(auth()->user()->can('administration.update') || (auth()->user()->can('inventory.update') && auth()->user()->can('finance.update')))
<section class="wh-panel">
<h2>Reconcile historical FIFO lot positions</h2>
<p class="wh-warning">Only assign lots after verifying physical counts, original GRN/adjustment records and warehouse history. This changes location attribution only; it cannot increase global stock, create purchase costs or override QC holds. At most 400 oldest open layers are reviewed in this register.</p>
<div class="wh-table"><table>
<thead><tr><th>Lot / Material</th><th>Source</th><th>Cost currency</th><th>Available for attribution</th><th>Verified location attribution</th></tr></thead><tbody>
@forelse($legacyLots as $layer)
<tr>
<td><strong>{{ $layer->material?->sku }}</strong><div>{{ $layer->material?->name }}</div><small>Lot #{{ $layer->id }} · {{ $layer->received_on?->toDateString() }}</small></td>
<td>{{ str_replace('_',' ',$layer->source_type) }} #{{ $layer->source_id }}</td>
<td>{{ $layer->currency_code ?: 'Unvalued' }}</td>
<td>{{ $layer->unpositioned_quantity }} {{ $layer->material?->unit }}</td>
<td>
<form action="{{ route('inventory.warehouse-stock.legacy.assign') }}" method="POST" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
@csrf
<input type="hidden" name="inventory_cost_layer_id" value="{{ $layer->id }}">
<select name="company_warehouse_id" aria-label="Verified warehouse" style="min-width:145px;padding:8px;border:1px solid #dce6f1;border-radius:8px">
<option value="">Unassigned stock pool</option>
@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach
</select>
<input type="text" inputmode="decimal" name="quantity" required placeholder="Quantity" aria-label="Attribution quantity" style="width:105px;padding:8px;border:1px solid #dce6f1;border-radius:8px">
<input type="text" name="reference" required minlength="3" maxlength="160" placeholder="Count / receipt reference" aria-label="Evidence reference" style="width:175px;padding:8px;border:1px solid #dce6f1;border-radius:8px">
<input type="text" name="reason" required minlength="15" maxlength="2000" placeholder="Document why this lot belongs here" aria-label="Evidence explanation" style="min-width:170px;flex:1;padding:8px;border:1px solid #dce6f1;border-radius:8px">
<button type="submit" class="btn" onclick="return confirm('Confirm this lot physically belongs to the selected location? The attribution will be audited.')">Attribute lot</button>
</form>
</td></tr>
@empty<tr><td colspan="5">No open FIFO cost layers need legacy attribution in the loaded batch.</td></tr>
@endforelse
</tbody></table></div>
</section>
@endif
<section class="wh-panel"><h2>Warehouse movement audit</h2>
<div class="wh-table"><table><thead><tr><th>Recorded</th><th>Material</th><th>Operation</th><th>Source</th><th>Destination</th><th>Quantity</th><th>FIFO lots</th><th>Reason</th></tr></thead><tbody>
@forelse($recent as $row)<tr><td>{{ $row->occurred_on }}</td><td>{{ $row->sku }}</td><td>{{ ucwords(str_replace('_',' ',$row->type)) }}</td><td>{{ $row->source?:'Unassigned' }}</td><td>{{ $row->destination }}</td><td>{{ $row->quantity }}</td>
<td>@if(isset($recentLotRows[$row->id])){{ $recentLotRows[$row->id]->lot_count }} source lot(s)
@else <span style="color:#94651e">Historical / unverified</span>@endif</td>
<td>{{ $row->reason }}</td></tr>
@empty<tr><td colspan="8">No warehouse allocations or transfers recorded yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
