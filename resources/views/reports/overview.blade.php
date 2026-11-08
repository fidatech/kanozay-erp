@extends('layouts.kanozay')
@section('content')
@php($t=fn(string $key)=>\App\Support\ReportingLabels::text($key))
<style>
.ops-shell{display:grid;gap:17px}
.ops-head{display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap}
.ops-head h1{margin:0 0 5px;font-size:23px}
.ops-head p{margin:0;color:#627b94;font-size:12px;line-height:1.7}
.ops-panel{border:1px solid #dce6f0;border-radius:12px;background:#fff;padding:20px}
.ops-filter{display:flex;align-items:end;flex-wrap:wrap;gap:11px}
.ops-filter label{display:grid;gap:6px;font-size:12px;font-weight:650}
.ops-filter input{padding:10px;border:1px solid #d9e5f1;border-radius:8px;min-width:158px}
.ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:11px}
.ops-card{border:1px solid #dce7f0;border-radius:10px;padding:14px;background:#fbfdff}
.ops-card .metric{display:block;font-size:23px;font-weight:800;font-variant-numeric:tabular-nums;color:#173c64;margin-top:7px}
.ops-card .title{font-size:12px;color:#536a81}
.ops-scroll{overflow-x:auto}
.ops-scroll table{min-width:720px}
.ops-panel h2{font-size:16px;margin:0 0 12px}
.ops-muted{color:#60758d;font-size:12px;line-height:1.7;margin:9px 0}
.ops-alert{font-size:12px;color:#825424;background:#fff7ed;padding:11px;border-radius:8px}
@media(max-width:600px){.ops-panel{padding:13px}.ops-filter input{min-width:130px}}
</style>
<div class="ops-shell">
<div class="ops-head">
 <div><h1>{{ $t('operations_overview') }}</h1>
 <p>{{ $t('overview_description') }}</p></div>
 <a class="btn btn-light" href="{{ route('workspace.page',array_merge(['reports','overview'],['from'=>$summary['from'],'to'=>$summary['to'],'export'=>'csv'])) }}">{{ $t('export_csv') }}</a>
</div>
<section class="ops-panel">
<form class="ops-filter" method="GET" action="{{ route('workspace.page',['reports','overview']) }}">
 <label>{{ $t('from') }}<input name="from" type="date" value="{{ $summary['from'] }}" required></label>
 <label>{{ $t('to') }}<input name="to" type="date" value="{{ $summary['to'] }}" required></label>
 <button type="submit" class="btn">{{ $t('apply_filters') }}</button>
 <a href="{{ route('workspace.page',['reports','overview']) }}" class="btn btn-light">{{ $t('reset') }}</a>
</form>
<p class="ops-muted">{{ $t('date_scope_warning') }}</p>
</section>
<section class="ops-panel">
<h2>{{ $t('recorded_operations') }}</h2>
<div class="ops-grid">
@foreach([
 'purchase_orders'=>'purchase_orders',
 'production_plans'=>'production_plans',
 'released_batches'=>'released_batches',
 'accepted_packages'=>'accepted_packages',
 'delivery_confirmations'=>'delivery_confirmations',
 'posted_journals'=>'posted_journals',
 'open_nonconformances'=>'open_nonconformances',
 'held_incoming_lots'=>'held_incoming_lots',
] as $label=>$metric)
<div class="ops-card">
<span class="title">{{ $t($label) }}</span>
<strong class="metric">{{ number_format((int)$summary['operations'][$metric]) }}</strong>
</div>
@endforeach
</div>
<p class="ops-muted">{{ $t('snapshot_scope_warning') }}</p>
</section>
<section class="ops-panel">
<h2>{{ $t('sales_by_currency') }}</h2>
<p class="ops-muted">{{ $t('sales_scope_warning') }}</p>
<div class="ops-scroll"><table>
<thead><tr><th>{{ $t('currency') }}</th><th>{{ $t('posted_invoice_count') }}</th>
<th>{{ $t('invoice_face_value') }}</th><th>{{ $t('credit_note_count') }}</th>
<th>{{ $t('credit_note_value') }}</th></tr></thead>
<tbody>
@forelse($summary['currencies'] as $row)
<tr><td>{{ $row['currency'] }}</td><td>{{ number_format($row['invoices']) }}</td>
<td style="font-variant-numeric:tabular-nums">{{ \App\Support\ReportingLabels::decimal($row['invoice_total']) }}</td>
<td>{{ number_format($row['credits']) }}</td>
<td style="font-variant-numeric:tabular-nums">{{ \App\Support\ReportingLabels::decimal($row['credit_total']) }}</td></tr>
@empty<tr><td colspan="5">{{ $t('no_records') }}</td></tr>
@endforelse
</tbody></table></div>
</section>
<section class="ops-panel">
<h2>{{ $t('laboratory_activity') }}</h2>
<div class="ops-scroll"><table>
<thead><tr><th>{{ $t('sample_source') }}</th><th>{{ $t('collected') }}</th>
<th>{{ $t('approved') }}</th><th>{{ $t('rejected') }}</th><th>{{ $t('pending') }}</th></tr></thead>
<tbody>
@foreach($summary['samples'] as $source=>$row)
<tr><td>{{ $source==='incoming_material'?$t('incoming_material'):$t('finished_batches') }}</td>
<td>{{ number_format($row['collected']) }}</td><td>{{ number_format($row['approved']) }}</td>
<td>{{ number_format($row['rejected']) }}</td><td>{{ number_format($row['pending']) }}</td></tr>
@endforeach
</tbody></table></div>
<p class="ops-muted">{{ $t('sample_scope_warning') }}</p>
</section>
<section class="ops-panel">
<h2>{{ $t('report_registers') }}</h2>
<div style="display:flex;gap:8px;flex-wrap:wrap">
@foreach(config('erp_pages.reports.pages',[]) as $page=>$pageTitle)
@if($page!=='overview')
<a class="btn btn-light" href="{{ route('workspace.page',['reports',$page]) }}">{{ \App\Support\ErpInterfaceLabels::page('reports',$page,$pageTitle) }}</a>
@endif
@endforeach
</div>
</section>
</div>
@endsection
