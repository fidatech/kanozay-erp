@extends('layouts.kanozay')
@section('content')
<style>
.uc-panel{background:white;border:1px solid #dfe8f3;border-radius:12px;padding:21px;margin-bottom:16px}
.uc-panel h2{margin:0 0 12px;font-size:15px}
.uc-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:end}
.uc-form label{display:grid;gap:7px;font-size:12px;color:#375575;font-weight:700}
.uc-form input,.uc-form select{height:43px;min-width:0;box-sizing:border-box;width:100%;padding:10px;border:1px solid #d8e3ef;border-radius:8px}
.uc-form .uc-wide{grid-column:1/-1}
.uc-note{padding:12px;border:1px solid #f2d9b5;border-radius:9px;color:#775019;background:#fffbf2;font-size:12px;line-height:1.6;margin:0 0 14px}
.uc-table{overflow:auto;border:1px solid #dfe8f3;border-radius:10px}
.uc-table table{min-width:760px;width:100%;margin:0;font-size:12px}
.uc-table td,.uc-table th{padding:10px 12px;text-align:start}
.uc-table th{background:#f0f6fc}
.uc-num{text-align:end!important;font-variant-numeric:tabular-nums}
.uc-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.uc-filter input,.uc-filter select{height:38px;padding:8px;border:1px solid #d8e3ef;border-radius:8px}
@media(max-width:650px){.uc-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Unit conversions</h1><p>Inventory / Explicit conversions between recipe, purchase and stock units.</p></div>
<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-light" href="{{ route('inventory.warehouse-stock.index') }}">Warehouse stock</a></div></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ $errors->first() }}</p>@endif

@if($canWrite)
<section class="uc-panel"><h2>Add a conversion</h2>
<p class="uc-note">Each row is one direction: <strong>1 “from” unit = factor “to” units</strong>. The reverse direction is not assumed; add it separately if it is needed. Quantities are converted exactly and are never rounded automatically.</p>
<form method="POST" action="{{ route('inventory.unit-conversions.store') }}" class="uc-form" onsubmit="this.querySelector('button[type=submit]').disabled=true">
@csrf
<label>From unit *<input name="from_unit" value="{{ old('from_unit') }}" maxlength="40" required placeholder="g"></label>
<label>To unit *<input name="to_unit" value="{{ old('to_unit') }}" maxlength="40" required placeholder="kg"></label>
<label>Factor *<input name="factor" value="{{ old('factor') }}" inputmode="decimal" required placeholder="0.001"></label>
<label>Only for this stock item (optional)<select name="inventory_material_id"><option value="">All items</option>
@foreach($materials as $material)<option value="{{ $material->id }}" @selected((string)old('inventory_material_id')===(string)$material->id)>{{ $material->sku }} — {{ $material->name }} ({{ $material->unit }})</option>@endforeach
</select></label>
<label class="uc-wide">Notes<input name="notes" value="{{ old('notes') }}" maxlength="500"></label>
<div class="uc-wide"><button type="submit" class="btn">Save conversion</button></div>
</form></section>
@endif

<section class="uc-panel"><h2>Defined conversions</h2>
<form method="GET" class="uc-filter">
<input name="search" value="{{ $filter['search'] ?? '' }}" placeholder="Search unit">
<select name="status"><option value="">All statuses</option><option value="active" @selected(($filter['status'] ?? '')==='active')>Active</option><option value="inactive" @selected(($filter['status'] ?? '')==='inactive')>Inactive</option></select>
<button class="btn btn-light" type="submit">Filter</button>
</form>
<div class="uc-table"><table><thead><tr><th>From</th><th>To</th><th class="uc-num">Factor</th><th>Applies to</th><th>Status</th><th>Notes</th><th>Added by</th><th></th></tr></thead><tbody>
@forelse($conversions as $conversion)
<tr>
<td><strong>1 {{ $conversion->from_unit }}</strong></td>
<td>{{ $conversion->to_unit }}</td>
<td class="uc-num">{{ rtrim(rtrim((string)$conversion->factor,'0'),'.') }}</td>
<td>{{ $conversion->material ? $conversion->material->sku.' — '.$conversion->material->name : 'All items' }}</td>
<td>{{ $conversion->is_active ? 'Active' : 'Inactive' }}</td>
<td>{{ $conversion->notes }}</td>
<td>{{ $conversion->creator?->name }}</td>
<td>@if($canWrite && $conversion->is_active)
<form method="POST" action="{{ route('inventory.unit-conversions.deactivate',$conversion) }}" onsubmit="return confirm('Deactivate this conversion? Existing documents keep their stored quantities.')">@csrf<button class="btn btn-light" type="submit">Deactivate</button></form>
@endif</td>
</tr>
@empty
<tr><td colspan="8">No unit conversions have been defined.</td></tr>
@endforelse
</tbody></table></div>
<div style="margin-top:12px">{{ $conversions->links() }}</div>
</section>
@endsection
