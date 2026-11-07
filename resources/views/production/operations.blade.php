@extends('layouts.kanozay')
@section('content')
<style>
.mo-panel{padding:20px;border:1px solid #dce7f2;background:#fff;border-radius:12px;margin-bottom:15px}
.mo-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.mo-form label{display:grid;gap:6px;font-size:12px;color:#446681}
.mo-form input,.mo-form select,.mo-form textarea{min-width:0;width:100%;box-sizing:border-box;padding:10px;border:1px solid #d6e4ef;border-radius:8px}
.mo-wrap{overflow:auto}
.mo-panel table{min-width:850px;font-size:12px}.mo-panel th,.mo-panel td{padding:12px}
.mo-warn{background:#fff7e6;color:#84632a;border-radius:8px;padding:12px;font-size:12px}
@media(max-width:900px){.mo-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $pageTitle }} · Manufacturing operations</h1><p>Equipment master data, production scheduling and recorded machine downtime</p></div><a class="btn btn-light" href="{{ route('production.orders.index') }}">Production orders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@if($page==='equipment')
@canany(['manufacturing.create','manufacturing.update','administration.update'])
<section class="mo-panel"><h2>Register production equipment</h2>
<form method="POST" class="mo-form" action="{{ route('manufacturing.equipment.store') }}">@csrf
<label>Equipment code<input name="code" required maxlength="40"></label>
<label>Machine / equipment name<input name="name" required maxlength="180"></label>
<label>Existing fixed asset<select name="fixed_asset_id"><option value="">Unlinked equipment</option>@foreach($fixedAssets as $a)<option value="{{ $a->id }}">{{ $a->asset_tag }} {{ $a->name }}</option>@endforeach</select></label>
<label>Capacity per hour<input name="capacity_per_hour" inputmode="decimal"></label>
<label>Capacity unit<input name="capacity_unit" placeholder="kg, bag, litre"></label>
<button class="btn" type="submit">Register equipment</button></form></section>
@endcanany
@endif
<section class="mo-panel"><h2>Equipment master register</h2><div class="mo-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Fixed asset</th><th>Rated capacity</th><th>Status</th></tr></thead><tbody>
@forelse($equipment as $e)<tr><td>{{ $e->code }}</td><td>{{ $e->name }}</td><td>{{ $e->fixed_asset_id?:'Not linked' }}</td><td>{{ $e->capacity_per_hour??'—' }} {{ $e->capacity_unit }}</td><td>{{ $e->is_active?'Active':'Inactive' }}</td></tr>
@empty<tr><td colspan="5">No production equipment registered.</td></tr>@endforelse
</tbody></table></div></section>
@if(in_array($page,['scheduling','plans','work-orders'],true))
@canany(['manufacturing.create','manufacturing.update','administration.update'])
<section class="mo-panel"><h2>Schedule a production order</h2>
<form method="POST" class="mo-form" action="{{ route('manufacturing.schedules.store') }}">@csrf
<label>Production order<select name="production_order_id" required><option value="">Choose planned order</option>@foreach($orders as $o)@if(in_array($o->status,['planned','reserved']))<option value="{{ $o->id }}">{{ $o->number }} · {{ $o->recipe?->name }}</option>@endif @endforeach</select></label>
<label>Machine<select name="manufacturing_equipment_id" required><option value="">Choose active equipment</option>@foreach($equipment as $e)@if($e->is_active)<option value="{{ $e->id }}">{{ $e->code }} · {{ $e->name }}</option>@endif @endforeach</select></label>
<label>Start date and time<input type="datetime-local" name="starts_at" required></label>
<label>End date and time<input type="datetime-local" name="ends_at" required></label>
<label style="grid-column:span 2">Notes<input name="notes" maxlength="3000"></label>
<button class="btn" type="submit">Save production schedule</button></form></section>
@endcanany
@endif
@if($page==='downtime')
@canany(['manufacturing.create','manufacturing.update','administration.update'])
<section class="mo-panel"><h2>Log actual machine downtime</h2>
<form class="mo-form" method="POST" action="{{ route('manufacturing.downtime.store') }}">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Equipment<select name="manufacturing_equipment_id" required><option value="">Select equipment</option>@foreach($equipment as $e)<option value="{{ $e->id }}">{{ $e->code }} {{ $e->name }}</option>@endforeach</select></label>
<label>Production order (optional)<select name="production_order_id"><option value="">Unallocated stoppage</option>@foreach($orders as $o)<option value="{{ $o->id }}">{{ $o->number }}</option>@endforeach</select></label>
<label>Reason category<select name="cause"><option value="mechanical">Mechanical</option><option value="electrical">Electrical</option><option value="utilities">Utilities</option><option value="materials">Materials</option><option value="quality">Quality hold</option><option value="setup">Setup</option><option value="other">Other</option></select></label>
<label>Started at<input type="datetime-local" name="started_at" required></label>
<label>Ended at<input type="datetime-local" name="ended_at" required></label>
<label>Description<textarea name="description" maxlength="3000" minlength="5" required></textarea></label>
<button class="btn" type="submit">Record downtime</button></form></section>
@endcanany
@endif
@if($page!=='equipment')
<section class="mo-panel"><h2>Equipment production schedule register</h2>
<div class="mo-wrap"><table><thead><tr><th>Order</th><th>Equipment</th><th>Start</th><th>Finish</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($schedules as $s)<tr><td><a href="{{ route('production.orders.show',$s->production_order_id) }}">{{ $s->order_number }}</a></td><td>{{ $s->equipment_code }}</td><td>{{ $s->starts_at }}</td><td>{{ $s->ends_at }}</td><td>{{ ucfirst($s->status) }}</td>
<td>@canany(['manufacturing.update','administration.update'])
@if(in_array($s->status,['planned','running']))
<form method="POST" action="{{ route('manufacturing.schedules.transition',$s->id) }}" style="display:flex;gap:8px">@csrf
<select name="status">@if($s->status==='planned')<option value="running">Start</option>@else<option value="completed">Complete</option>@endif<option value="cancelled">Cancel</option></select>
<button class="btn btn-secondary">Save</button></form>@endif
@endcanany</td></tr>
@empty<tr><td colspan="6">No equipment schedules entered.</td></tr>@endforelse
</tbody></table></div><p class="mo-warn">A schedule does not reserve raw materials or post production quantities; use the production-order workflow to reserve, issue, inspect and release finished goods.</p></section>
@endif
@if(in_array($page,['downtime','equipment','scheduling'],true))
<section class="mo-panel"><h2>Actual equipment downtime history</h2><div class="mo-wrap"><table><thead><tr><th>Machine</th><th>Started</th><th>Ended</th><th>Duration (min)</th><th>Cause</th><th>Notes</th></tr></thead><tbody>
@forelse($downtimes as $d)<tr><td>{{ $d->equipment_code }}</td><td>{{ $d->started_at }}</td><td>{{ $d->ended_at }}</td><td>{{ $d->duration_minutes }}</td><td>{{ ucfirst($d->cause) }}</td><td>{{ $d->description }}</td></tr>
@empty<tr><td colspan="6">No downtime registered.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
