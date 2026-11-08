@extends('layouts.kanozay')
@section('content')
@php($t=fn(string $key)=>\App\Support\ReportingLabels::text($key))
<style>
.dcr-sheet{background:#fff;border:1px solid #dce6f0;border-radius:11px;padding:18px;margin:14px 0}
.dcr-warning{background:#fff7ec;border:1px solid #f1dbb4;border-radius:9px;padding:14px;color:#735122;line-height:1.7}
.dcr-scroll{overflow-x:auto}.dcr-scroll table{min-width:1120px}
.dcr-scroll th,.dcr-scroll td{padding:11px;vertical-align:top}
.dcr-bad{color:#aa3626;font-weight:700}.dcr-est{color:#95611e;font-weight:700}
.dcr-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.dcr-filters label{display:grid;gap:6px}
.dcr-summary{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}
.dcr-summary>div{border:1px solid #dce6f0;border-radius:9px;padding:12px;min-width:180px}
</style>
<div class="heading">
<div><h1>{{ $t('dispatch_cost_title') }}</h1>
<p>{{ $t('dispatch_cost_description') }}</p></div>
<div class="actions">
<a class="btn btn-light" href="{{ route('manufacturing.cost-readiness.index') }}">{{ $t('batch_cost_readiness') }}</a>
<a class="btn" href="{{ route('reports.dispatch-cost.index',array_merge(request()->query(),['export'=>'csv'])) }}">{{ $t('export_csv') }}</a>
</div>
</div>
<div class="dcr-warning">
<strong>{{ $t('dispatch_cost_warning') }}</strong>
<div class="muted">Unknown or mixed currencies, missing FIFO costs and pending overhead block source estimates. Customer credits and physical returns require independent inventory-cost reconciliation.</div>
</div>
<section class="dcr-sheet">
<form method="GET" action="{{ route('reports.dispatch-cost.index') }}" class="dcr-filters">
<label>{{ $t('dispatch_from') }}<input name="from" type="date" value="{{ $from }}" required></label>
<label>{{ $t('dispatch_to') }}<input name="to" type="date" value="{{ $to }}" required></label>
<button class="btn" type="submit">{{ $t('apply_filters') }}</button>
<a class="btn btn-light" href="{{ route('reports.dispatch-cost.index') }}">{{ $t('reset') }}</a>
</form>
<div class="dcr-summary">
<div><span>{{ $t('dispatch_estimated_lines') }}</span><h2>{{ number_format($summary['costed_lines']) }}</h2></div>
<div><span>{{ $t('dispatch_blocked_lines') }}</span><h2>{{ number_format($summary['blocked_lines']) }}</h2></div>
@foreach($summary['by_currency'] as $currency)
<div><span>{{ $currency['currency'] }} estimated source cost, current page</span>
<h2>{{ \App\Support\ReportingLabels::decimal($currency['indicative_cost']) }}</h2>
<small>{{ $currency['packages'] }} packages</small></div>
@endforeach
</div>
<p class="muted">{{ $t('dispatch_page_totals') }}</p>
</section>
<section class="dcr-sheet">
<div class="dcr-scroll"><table>
<thead><tr>
<th>{{ $t('dispatch_date') }}</th><th>{{ $t('dispatch_reference') }}</th><th>{{ $t('dispatch_batch_product') }}</th>
<th>{{ $t('dispatch_packages') }}</th><th>{{ $t('dispatch_source_currency') }}</th><th>{{ $t('dispatch_unit_cost') }}</th>
<th>{{ $t('dispatch_total_cost') }}</th><th>{{ $t('dispatch_readiness') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td>{{ $row['date'] }}</td>
<td>{{ $row['reference'] }}</td>
<td><strong>{{ $row['batch'] }}</strong><div class="muted">{{ $row['product'] }}</div></td>
<td>{{ number_format($row['packages']) }}</td>
<td>{{ $row['currency']??'Unknown' }}</td>
<td>{{ $row['unit_cost']??$t('dispatch_cost_not_ready') }}</td>
<td>{{ $row['indicative_cost']??$t('dispatch_cost_not_ready') }}</td>
<td>
@if($row['status']==='blocked')
<span class="dcr-bad">Blocked</span>
@foreach($row['reasons'] as $reason)<div class="muted">{{ $reason }}</div>@endforeach
@else <span class="dcr-est">{{ $t('dispatch_source_only') }}</span>
@endif
</td>
</tr>
@empty<tr><td colspan="8">{{ $t('dispatch_no_rows') }}</td></tr>
@endforelse
</tbody></table></div>
{{ $records->links() }}
</section>
@endsection
