@extends('layouts.kanozay')
@section('content')
<style>
.al-panel{border:1px solid #e0e9f3;border-radius:12px;background:#fff;padding:20px;margin:14px 0}
.al-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.al-form label{display:grid;gap:5px;font-size:12px;color:#365773;font-weight:700}
.al-form input,.al-form select,.al-form textarea{min-width:0;width:100%;padding:10px;border-radius:8px;border:1px solid #d5e2ef;box-sizing:border-box}
.al-form .full{grid-column:1/-1}
.al-panel table{min-width:790px;font-size:12px}.al-panel th,.al-panel td{padding:12px}
.al-wrap{overflow-x:auto}
.al-warning{padding:12px;border-radius:8px;background:#fff7e6;color:#775821;font-size:12px;line-height:1.7}
.al-nav{display:flex;gap:9px;flex-wrap:wrap}
.al-nav a{border:1px solid #d8e5f3;border-radius:8px;padding:9px 11px;text-decoration:none;font-size:12px}
@media(max-width:820px){.al-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Asset lifecycle · {{ $pageTitle }}</h1><p>Audited equipment care, location, retirement and service records</p></div><a class="btn btn-light" href="{{ route('workspace.page',['assets','register']) }}">Asset register</a></div>
<div class="al-nav">
@foreach(['maintenance','schedules','service-history','transfers','disposals'] as $slug)
<a href="{{ route('workspace.page',['assets',$slug]) }}" aria-current="{{ $slug===$page?'page':'false' }}">{{ config('erp_pages.assets.pages.'.$slug) }}</a>
@endforeach</div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
@if(in_array($page,['maintenance','schedules','service-history'],true))
@if($page!=='service-history')
@canany(['maintenance.update','administration.update'])
<div class="al-panel"><h2>New maintenance request</h2>
<form method="POST" action="{{ route('assets.lifecycle.maintenance.store') }}" class="al-form">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Asset<select name="fixed_asset_id" required><option value="">Choose an active asset</option>@foreach($assets as $a)@if($a->status==='active')<option value="{{ $a->id }}">{{ $a->asset_tag }} · {{ $a->name }}</option>@endif @endforeach</select></label>
<label>Service type<select name="type"><option value="preventive">Preventive</option><option value="corrective">Corrective</option></select></label>
<label>Planned date<input name="planned_on" type="date" min="{{ now()->toDateString() }}"></label>
<label class="full">Request summary<input name="summary" maxlength="180" required></label>
<label>Provider<input name="provider" maxlength="160"></label>
<label class="full">Notes<textarea name="notes" rows="2" maxlength="3000"></textarea></label>
<button class="btn" type="submit">Create service request</button></form>
</div>
@endcanany
@endif
<div class="al-panel"><h2>{{ $page==='service-history'?'Completed service history':($page==='schedules'?'Upcoming service schedules':'Maintenance orders') }}</h2>
<div class="al-wrap"><table><thead><tr><th>Ticket</th><th>Asset</th><th>Issue</th><th>Planned</th><th>Status</th><th>Cost</th><th>Action</th></tr></thead><tbody>
@forelse($tasks as $task)
<tr><td>{{ $task->ticket }}</td><td>{{ $task->asset_tag }}</td><td>{{ $task->summary }}<br><small>{{ ucfirst($task->type) }}</small></td><td>{{ $task->planned_on?:'—' }}</td><td>{{ ucwords(str_replace('_',' ',$task->status)) }}</td><td>{{ $task->actual_cost??'—' }}</td>
<td>@canany(['maintenance.update','administration.update'])
@if(!in_array($task->status,['completed','cancelled'],true))
<form method="POST" action="{{ route('assets.lifecycle.maintenance.update',$task->id) }}" style="display:grid;gap:6px">@csrf
<select name="status" required>
@if($task->status==='requested')<option value="scheduled">Schedule</option><option value="in_progress">Start work</option>@endif
@if($task->status==='scheduled')<option value="in_progress">Start work</option>@endif
@if($task->status==='in_progress')<option value="completed">Complete work</option>@endif
<option value="cancelled">Cancel</option>
</select>
<input type="date" name="planned_on" value="{{ $task->planned_on }}" aria-label="Planned date">
<input type="date" name="serviced_on" max="{{ now()->toDateString() }}" aria-label="Service completed date">
<input name="actual_cost" inputmode="decimal" placeholder="Actual cost on completion">
<input name="completion_notes" placeholder="Completion notes" maxlength="3000">
<button class="btn btn-secondary">Record transition</button>
</form>
@endif
@endcanany
@if($task->status==='completed')<small>Completed {{ $task->serviced_on }}: {{ $task->completion_notes }}</small>@endif</td></tr>
@empty<tr><td colspan="7">No matching service records.</td></tr>@endforelse
</tbody></table></div>{{ $tasks->links() }}
<p class="al-warning">Maintenance costs are operational notes in the asset's acquisition currency. They do not create an accounting journal until reconciled in Finance.</p>
</div>
@elseif($page==='transfers')
@canany(['maintenance.update','administration.update'])
<div class="al-panel"><h2>Move an asset between warehouse locations</h2>
<form class="al-form" method="POST" action="" id="al-transfer">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Asset<select required onchange="this.form.action='{{ url('/assets') }}/'+this.value+'/location-transfer'"><option value="">Choose unassigned asset</option>
@foreach($assets as $a)@if($a->status==='active' && !$a->custodian_id)<option value="{{ $a->id }}">{{ $a->asset_tag }} · {{ $a->name }}</option>@endif @endforeach</select></label>
<label>Destination<select name="destination_warehouse_id" required><option value="">Choose location</option>
@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} · {{ $w->name }}</option>@endforeach</select></label>
<label>Transfer date<input type="date" name="moved_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label class="full">Transfer reason<input name="reason" minlength="5" maxlength="500" required></label>
<button class="btn" type="submit">Record location transfer</button></form>
</div>@endcanany
<div class="al-panel"><h2>Asset location movements</h2><div class="al-wrap"><table><thead><tr><th>Asset</th><th>From</th><th>To</th><th>Date</th><th>Reason</th></tr></thead><tbody>
@forelse($movements as $movement)<tr><td>{{ $movement->asset_tag }}</td><td>{{ $movement->source?:'Unassigned' }}</td><td>{{ $movement->destination }}</td><td>{{ $movement->moved_on }}</td><td>{{ $movement->reason }}</td></tr>
@empty<tr><td colspan="5">No asset transfers.</td></tr>@endforelse
</tbody></table></div></div>
@elseif($page==='disposals')
<div class="al-panel"><h2>Physical asset disposals</h2>
<p class="al-warning">The asset register does not automatically post acquisition cost to the General Ledger. Disposal can therefore record the physical event and book-value snapshot, but the resulting gain or loss and proceeds must be reconciled against verified acquisition journals in Finance. This page never creates fictional accounting balances.</p>
@canany(['finance.update','administration.update'])
<form method="POST" class="al-form" id="al-disposal">@csrf
<label>Active asset<select required onchange="this.form.action='{{ url('/assets') }}/'+this.value+'/disposal'"><option value="">Choose returned asset</option>
@foreach($assets as $asset)@if($asset->status==='active' && !$asset->custodian_id)<option value="{{ $asset->id }}">{{ $asset->asset_tag }} · {{ $asset->name }}</option>@endif @endforeach
</select></label>
<label>Disposal date<input type="date" name="disposed_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Method<select name="method" required><option value="sale">Sale</option><option value="scrap">Scrap</option><option value="donation">Donation</option><option value="write_off">Write-off</option></select></label>
<label>Proceeds (asset currency)<input name="proceeds" inputmode="decimal" value="0" required></label>
<label class="full">Reason and supporting approval reference<textarea name="reason" required minlength="10" maxlength="3000"></textarea></label>
<label class="full"><span><input type="checkbox" name="confirm_accounting_pending" value="1" required style="width:auto"> I confirm the asset is physically disposed and finance reconciliation is still required.</span></label>
<div><button class="btn" type="submit" onclick="return confirm('Retire this asset from operational use?')">Record physical disposal</button></div>
</form>
@endcanany</div>
<div class="al-panel"><h2>Disposal register</h2><div class="al-wrap"><table><thead><tr><th>Asset</th><th>Date</th><th>Method</th><th>Acquisition less depreciation</th><th>Proceeds</th><th>Financial status</th></tr></thead><tbody>
@forelse($disposals as $d)<tr><td>{{ $d->asset_tag }}</td><td>{{ $d->disposed_on }}</td><td>{{ ucfirst($d->method) }}</td><td>{{ $d->currency_code }} {{ $d->book_value }}<br><small>Depreciation: {{ $d->accumulated_depreciation }}</small></td><td>{{ $d->proceeds }}</td><td>Pending reconciliation</td></tr>
@empty<tr><td colspan="6">No disposed assets.</td></tr>@endforelse
</tbody></table></div></div>
@endif
@endsection
