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
<div class="rq-callout">A customer credit note registers non-saleable quarantine without increasing on-hand inventory. Independent QC decides saleable release or scrap. Saleable release now also requires the original COGS evidence and posts a matching finished-goods asset recovery in the factory cost currency. Historical released stock without its original cost journal needs finance reconciliation. A signed scrapped return never restores finished inventory and can only reclassify genuine previously posted sale COGS to an independently approved loss expense. Held QC returns create no asset and no new expense.</div>
<section class="rq-panel"><form method="GET" action="{{ route('sales.returns.quarantine.index') }}" style="display:flex;gap:10px">
<select name="status"><option value="">All return statuses</option>
@foreach(['quarantined'=>'Awaiting QC','released'=>'Released to stock','scrapped'=>'Scrapped'] as $id=>$name)
<option value="{{ $id }}" @selected(request('status')===$id)>{{ $name }}</option>@endforeach</select>
<button class="btn btn-light">Filter</button></form></section>
<section class="rq-panel"><h2>Quarantined return line register</h2><div class="rq-wrap">
<table><thead><tr><th>Credit note line</th><th>Finished product</th><th>Batch</th><th>Packages</th><th>QC status</th><th>Inspector / disposition</th>@if($mayViewCosts)<th>Original-currency asset recovery</th>@endif</tr></thead><tbody>
@forelse($entries as $q)
<tr><td>#{{ $q->sales_credit_note_line_id }}</td>
<td>{{ $q->creditLine?->invoiceLine?->finishedGood?->name }}</td>
<td>{{ $q->batch?->batch_number }}</td><td>{{ $q->quantity_packages }}</td>
<td>{{ ucwords(str_replace('_',' ',$q->status)) }}
@if($q->quality_reference)<div>{{ $q->quality_reference }}</div>@endif</td>
<td>@if($q->status==='quarantined')
@if(auth()->user()->can('administration.update') ||
    (auth()->user()->can('laboratory.update') && auth()->user()->can('inventory.update')))
<form method="POST" class="rq-form" action="{{ route('sales.returns.quarantine.decision',$q) }}">@csrf
<select name="action"><option value="release">Release after QC</option><option value="scrap">Scrap / reject</option></select>
<select name="company_warehouse_id"><option value="">Use source location / legacy unassigned stock</option>
@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach
</select>
<input name="decided_on" type="date" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
<input name="quality_reference" minlength="3" maxlength="160" placeholder="Approved QC reference" required>
<textarea name="decision_notes" minlength="10" maxlength="3000" placeholder="Inspection evidence and release / scrap decision" required></textarea>
<button class="btn" type="submit">Record QC decision</button></form>
@endif
@else<div>{{ $q->decision_notes }}</div><small>On {{ $q->decided_on?->format('Y-m-d') }}</small>@endif
</td>
@if($mayViewCosts)
@php($recovery=$returnCosts->get($q->id))
@php($disposition=$returnDispositions->get($q->id))
<td>
@if($q->status==='released' && $recovery?->journal_status==='posted')
<strong style="color:#167b51">{{ $recovery->currency_code }} <x-finance-amount :amount="$recovery->amount" /></strong>
<div class="muted">GL {{ $recovery->journal_number }} · Asset debit / COGS credit</div>
@elseif($q->status==='released')
<strong style="color:#a6462d">Historical cost not reconciled</strong>
<div><a href="{{ route('finance.historical-corrections.index',[
  'type'=>'released_return_missing_cost','source_id'=>$q->id
]) }}">Investigate original credit/QC/COGS evidence</a></div>
@elseif($q->status==='scrapped')
@if($disposition?->treatment==='scrap_cogs_reclass' && $disposition?->status==='posted' && $disposition?->journal_status==='posted')
<strong style="color:#167b51">Original COGS reclassified to approved scrap loss: {{ $disposition->source_loss_amount }} {{ $disposition->currency_code }}</strong>
<div class="muted">GL {{ $disposition->journal_number }} · Dr loss expense / Cr source COGS · No finished-stock asset</div>
@else
<span class="muted">No saleable asset restored; original sales COGS remains an expense.</span>
<div>{{ $disposition?->status==='approved' ? 'Original source loss reclassification approved; awaiting separate finance executor.' : 'Independent source loss review is pending or has not been submitted.' }}</div>
<div><a href="{{ route('finance.historical-corrections.index',[
  'type'=>'customer_return_disposition','source_id'=>$q->id
]) }}">Review original customer credit, QC scrap and COGS loss treatment</a></div>
@endif
@else
@if($disposition?->treatment==='quarantine_hold' && $disposition?->status==='held')
<strong class="muted">Independently reviewed: quarantine hold only</strong>
<div>No inventory asset, expense or GL journal posted. Await independent QC disposition.</div>
@else
<span class="muted">QC held; no saleable inventory or loss recognized</span>
<div><a href="{{ route('finance.historical-corrections.index',[
  'type'=>'customer_return_disposition','source_id'=>$q->id
]) }}">Document original customer credit and QC quarantine hold</a></div>
@endif
@endif
</td>
@endif
</tr>
@empty<tr><td colspan="{{ $mayViewCosts?7:6 }}">No quarantined customer returns recorded.</td></tr>@endforelse
</tbody></table></div>{{ $entries->links() }}</section>
@endsection