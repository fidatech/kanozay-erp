@extends('layouts.kanozay')
@section('content')
<style>
.dh-panel{background:#fff;border:1px solid #dce6ef;padding:20px;border-radius:12px;margin:14px 0}
.dh-table{overflow:auto;border:1px solid #dfe7ef;border-radius:9px}
.dh-table table{width:100%;min-width:800px;margin:0;font-size:12px}
.dh-table td,.dh-table th{padding:12px;border-bottom:1px solid #e5edf5;white-space:normal;max-width:320px;vertical-align:top}
.dh-table th{background:#f1f6fb;text-align:left}
.dh-info{padding:12px;background:#f5f9fd;border-left:3px solid #6789a7;color:#385a74;border-radius:6px;line-height:1.6;font-size:12px}
.dh-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
</style>
<div class="heading"><div><h1>{{ $definition['title'] }}</h1>
<p>{{ config('erp_pages.'.$module.'.label') }} · Source-linked operating records</p></div>
<div class="dh-actions">
<a class="btn btn-light" href="{{ route('data-hubs.export',[$module,$page]) }}">Export CSV</a>
@if($definition['action'] && Route::has($definition['action']))
<a class="btn btn-secondary" href="{{ route($definition['action']) }}">Open related register</a>@endif
</div></div>
<p class="dh-info">{{ $definition['note'] }} The rows below come from posted or existing application records; this view does not perform a new posting or quietly change data.</p>
<section class="dh-panel">
<div class="dh-table"><table><thead><tr>
@foreach($definition['columns'] as $heading)<th>{{ $heading }}</th>@endforeach
@if($definition['link'])<th>Source</th>@endif
</tr></thead><tbody>
@forelse($rows as $record)
<tr>
@foreach(array_keys($definition['columns']) as $index=>$key)
<td>{{ $record->{'col'.$index}??'—' }}</td>
@endforeach
@if($definition['link'])
<td><a href="{{ route($definition['link'],$record->_source_id) }}">View source</a></td>
@endif
</tr>
@empty<tr><td colspan="{{ count($definition['columns'])+($definition['link']?1:0) }}">There are no records in this register yet.</td></tr>
@endforelse
</tbody></table></div>
{{ $rows->links() }}
</section>
@endsection
