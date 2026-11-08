@extends('layouts.kanozay')
@section('content')
<style>
.report-center .report-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.report-center .report-head h1{margin:0 0 6px;font-size:23px;color:#193e69}
.report-center .report-head p{margin:0;color:#667f9a;font-size:12px}
.report-center .report-filters{display:grid;grid-template-columns:minmax(160px,2fr) repeat(2,minmax(140px,1fr)) auto;align-items:end;gap:12px}
.report-center .report-filters label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#395979}
.report-center .report-filters input{width:100%;min-height:42px;border:1px solid #d9e5f2;border-radius:9px;padding:9px 11px}
.report-center .report-actions{display:flex;flex-wrap:wrap;gap:8px}
.report-center .report-actions a,.report-center .report-actions button{padding:11px 16px;border-radius:9px;text-decoration:none;font-weight:700;font-size:12px;white-space:nowrap}
.report-center .report-actions .outline{border:1px solid #cbdced;background:#fff;color:#265786}
.report-center .report-note{background:#f4f8fd;border:1px solid #e1eaf5;padding:13px 16px;border-radius:10px;font-size:12px;color:#536f8e;margin:14px 0}
.report-center .report-table{border:1px solid #dfe8f4;border-radius:10px;overflow:auto}
.report-center .report-table table{margin:0;min-width:750px;font-size:12px}
.report-center .report-table th{background:#f2f7fd;text-transform:uppercase;font-size:10px;letter-spacing:.4px;padding:13px 12px}
.report-center .report-table td{padding:12px;vertical-align:middle;max-width:280px;overflow-wrap:anywhere}
.report-center .report-table a{color:#1264ad;font-weight:800}
.report-center .report-count{font-size:12px;color:#68809a}
@media(max-width:850px){.report-center .report-filters{grid-template-columns:1fr 1fr}.report-center .report-filters .search-filter{grid-column:1/-1}}
@media(max-width:560px){.report-center .report-filters{grid-template-columns:1fr}.report-center .report-filters .search-filter{grid-column:auto}}
</style>
@php($t=fn(string $key)=>\App\Support\ReportingLabels::text($key))
<div class="report-center">
<div class="report-head"><div><h1><i class="fa-solid fa-chart-column"></i> {{ $title }}</h1><p>{{ \App\Support\ErpInterfaceLabels::module('reports') }} / {{ $title }}</p></div>
<a class="btn" href="{{ route('workspace.page', ['reports', $report] + array_filter(['search'=>$filters['search']??null,'from'=>$dateColumn ? ($filters['from']??null) : null,'to'=>$dateColumn ? ($filters['to']??null) : null]) + ['export'=>'csv']) }}"><i class="fa-solid fa-file-csv"></i> {{ $t('export_csv') }}</a></div>
<section class="panel" style="padding:19px">
<form class="report-filters" method="GET" action="{{ route('workspace.page',['reports',$report]) }}">
<label class="search-filter">{{ $t('search_records') }}<input name="search" type="search" maxlength="100" value="{{ $filters['search']??'' }}" placeholder="{{ $t('search_hint') }}"></label>
@if($dateColumn)
<label>{{ $t('from') }}<input name="from" type="date" value="{{ $filters['from']??'' }}"></label>
<label>{{ $t('to') }}<input name="to" type="date" value="{{ $filters['to']??'' }}"></label>
@endif
<div class="report-actions"><button type="submit" class="btn"><i class="fa-solid fa-filter"></i> {{ $t('filter') }}</button><a class="outline" href="{{ route('workspace.page',['reports',$report]) }}">{{ $t('reset') }}</a></div>
</form>
<div class="report-note"><strong>{{ $t('reporting_note') }}:</strong> {{ $note }}</div>
<div class="report-head" style="margin:12px 0"><h2 style="margin:0;font-size:15px">{{ $t('report_register') }}</h2><span class="report-count">{{ number_format($records->total()) }} {{ $t('matching_records') }}</span></div>
<div class="report-table">
<table><thead><tr>@foreach($columns as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
<tbody>
@forelse($records as $record)
<tr>@foreach($record['cells'] as $index=>$value)
<td>@if($index===0 && !empty($record['url']))<a href="{{ $record['url'] }}">{{ $value }}</a>@else {{ $value }} @endif</td>
@endforeach</tr>
@empty
<tr><td colspan="{{ count($columns) }}" style="padding:32px;text-align:center;color:#7188a1">{{ $t('no_filter_matches') }}</td></tr>
@endforelse
</tbody></table></div>
{{ $records->links() }}
</section></div>
@endsection
