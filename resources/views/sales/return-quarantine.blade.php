@extends('layouts.kanozay')
@section('content')
<style>
.rq-panel{padding:20px;background:#fff;border:1px solid #dce7f1;border-radius:11px;margin:15px 0}
.rq-wrap{overflow:auto}.rq-panel table{min-width:920px;font-size:12px}
.rq-panel th,.rq-panel td{padding:11px;vertical-align:top}
.rq-form{display:grid;gap:8px;min-width:240px}.rq-form input,.rq-form select,.rq-form textarea{padding:8px;border:1px solid #d4dfec;border-radius:7px;width:100%}
.rq-callout{padding:14px;border-radius:8px;background:#fff7e9;color:#72541b;line-height:1.6}
</style>
<div class="heading"><div><h1>Sales Returns — Quarantine & QC Disposition</h1><p>Separate physically returned goods from approved saleable warehouse stock.</p></div><a class="btn btn-light" href="{{ route('workspace.page',['sales','returns']) }}">Sales returns register</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<div class="rq-callout">A non-saleable return is registered in quarantine when its credit note is posted. It does not increase on-hand inventory. A different QC reviewer must document either release to saleable stock or scrapping; the process is one-way and cannot be repeated.</div>
<section class="rq-panel"><form method="GET" action="{{ route('sales.returns.quarantine.index') }}" style="display:flex;gap:10px">
<select name="status"><option value="">All return statuses</option>
@foreach(['quarantined'=>'Awaiting QC','released'=>'Released to stock','scrapped'=>'Scrapped'] as $id=>$name)
<option value="{{ $id }}" @selected(request('status')===$id)>{{ $name }}</option>@endforeach</select>
<button class="btn btn-light">Filter</button></form></section>
<section class="rq-panel"><h2>Quarantined return line register</h2><div class="rq-wrap">
<table><thead><tr><th>Credit note line</th><th>Finished product</th><th>Batch</th><th>Packages</th><th>QC status</th><th>Inspector / disposition</th></tr></thead><tbody>
@forelse($entries as $q)
<tr><td>#{{ $q->sales_credit_note_line_id }}</td>
<td>{{ $q->creditLine?->invoiceLine?->finishedGood?->name }}</td>
<td>{{ $q->batch?->batch_number }}</td><td>{{ $q->quantity_packages }}</td>
<td>{{ ucwords(str_replace('_',' ',$q->status)) }}
@if($q->quality_reference)<div>{{ $q->quality_reference }}</div>@endif</td>
<td>@if($q->status==='quarantined')
@canany(['administration.update','laboratory.update'])
<form method="POST" class="rq-form" action="{{ route('sales.returns.quarantine.decision',$q) }}">@csrf
<select name="action"><option value="release">Release after QC</option><option value="scrap">Scrap / reject</option></select>
<select name="company_warehouse_id"><option value="">Use source location / legacy unassigned stock</option>
@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach
</select>
<input name="decided_on" type="date" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
<input name="quality_reference" minlength="3" maxlength="160" placeholder="Approved QC reference" required>
<textarea name="decision_notes" minlength="10" maxlength="3000" placeholder="Inspection evidence and release / scrap decision" required></textarea>
<button class="btn" type="submit">Record QC decision</button></form>
@endcanany
@else<div>{{ $q->decision_notes }}</div><small>On {{ $q->decided_on?->format('Y-m-d') }}</small>@endif
</td></tr>
@empty<tr><td colspan="6">No quarantined customer returns recorded.</td></tr>@endforelse
</tbody></table></div>{{ $entries->links() }}</section>
@endsection