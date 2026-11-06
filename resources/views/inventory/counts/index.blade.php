@extends('layouts.kanozay')
@section('content')
<style>
.count-workspace .heading{margin-bottom:18px}.count-workspace .count-panel{padding:20px;border-radius:13px}
.count-workspace .count-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:13px}
.count-workspace .count-toolbar p{font-size:12px;color:#6e829c;margin:0}
.count-workspace .count-grid{display:grid;grid-template-columns:190px 1fr;gap:12px;margin-bottom:12px}
.count-workspace .count-grid label{font-size:12px;font-weight:700;display:grid;gap:6px}
.count-workspace .count-grid input{padding:10px;min-width:0}
.count-workspace .count-table{max-height:380px;overflow:auto;border:1px solid #e1eaf4;border-radius:10px}
.count-workspace table{min-width:620px;margin:0;font-size:12px}.count-workspace th{position:sticky;top:0}
.count-workspace .count-table input{width:145px;text-align:right}
.count-workspace .count-note{margin:12px 0;color:#5b7290;font-size:12px;line-height:1.7}
.count-workspace .error-list{padding:12px;color:#9b2828;background:#fff2f2;border-radius:8px;margin-bottom:12px}
@media(max-width:700px){.count-workspace .count-grid{grid-template-columns:1fr}}
</style>
<div class="count-workspace">
<div class="heading"><div><h1>Physical Stock Counts</h1><p>Inventory / Physical Stock Counts · Current company-wide raw-material register</p></div><a class="btn btn-light" href="{{ route('inventory.materials.index') }}">Material catalogue</a></div>
@if(session('success'))<div class="panel" style="border-color:#c6e7d5;color:#216846">{{ session('success') }}</div>@endif
@if($errors->any())<div class="error-list">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@can('inventory.create')
<section class="panel count-panel">
<div class="count-toolbar"><div><h2 style="margin:0 0 5px">New physical count</h2><p>Enter counts only for materials physically checked. Empty fields are excluded.</p></div><span class="status">Draft until approval</span></div>
<form method="POST" action="{{ route('inventory.counts.store') }}" style="display:block">
@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<div class="count-grid"><label>Count date<input type="date" name="counted_on" required max="{{ now()->toDateString() }}" value="{{ old('counted_on',now()->toDateString()) }}"></label>
<label>Reference / reason<input name="notes" maxlength="1000" placeholder="Count team, room and reason" value="{{ old('notes') }}"></label></div>
<div class="count-table"><table><thead><tr><th>SKU</th><th>Material</th><th>Unit</th><th>Current on hand</th><th>Counted quantity</th></tr></thead><tbody>
@forelse($materials as $material)
<tr><td>{{ $material->sku }}</td><td>{{ $material->name }}</td><td>{{ $material->unit }}</td><td>{{ $material->on_hand_quantity }}</td>
<td><input type="text" inputmode="decimal" name="quantities[{{ $material->id }}]" value="{{ old('quantities.'.$material->id) }}" placeholder="Leave empty to skip" aria-label="Count for {{ $material->sku }}"></td></tr>
@empty<tr><td colspan="5">Create active materials before recording a physical stock count.</td></tr>@endforelse
</tbody></table></div>
<div class="count-note">At most 100 materials per count. Counts are company-wide because this inventory ledger does not currently record per-warehouse stock. A discrepancy will be posted as an adjustment only after approval. Added stock will remain unvalued unless a separate approved cost record exists.</div>
<button type="submit" class="btn" style="margin-top:10px">Save count for review</button>
</form>
</section>
@endcan
<section class="panel count-panel"><h2>Saved physical counts</h2>
<div class="table-wrap"><table><thead><tr><th>Number</th><th>Date</th><th>Status</th><th>Created by</th><th>Open</th></tr></thead><tbody>
@forelse($counts as $count)
<tr><td>{{ $count->number }}</td><td>{{ $count->counted_on?->format('Y-m-d') }}</td><td><span class="status">{{ ucfirst($count->status) }}</span></td><td>{{ $count->creator?->name ?? '—' }}</td><td><a href="{{ route('inventory.counts.show',$count) }}">Review count</a></td></tr>
@empty<tr><td colspan="5">No physical counts have been recorded.</td></tr>@endforelse
</tbody></table></div>
{{ $counts->links() }}
</section></div>
@endsection
