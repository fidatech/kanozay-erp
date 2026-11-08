@extends('layouts.kanozay')
@section('content')
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
<div><h1>Finished-Goods Dispatch Cost Reconciliation</h1>
<p>Original-currency batch-source costs, with explicit journal and valuation limitations.</p></div>
<div class="actions">
<a class="btn btn-light" href="{{ route('manufacturing.cost-readiness.index') }}">Batch cost readiness</a>
<a class="btn" href="{{ route('reports.dispatch-cost.index',array_merge(request()->query(),['export'=>'csv'])) }}">Export CSV</a>
</div>
</div>
<div class="dcr-warning">
<strong>Management source-cost estimate only. No COGS entry is posted.</strong>
Costs originate from frozen FIFO material consumption snapshots plus independently approved production overhead source expenses. They are not proof that all factory costs were capitalized to WIP or finished-goods inventory in the General Ledger.
These numbers exclude unknown or mixed-currency batches, unvalued source lots, pending overhead and unresolved historic records.
A customer sales credit or physical return must be reconciled separately; it does not automatically reverse source inventory cost.
</div>
<section class="dcr-sheet">
<form method="GET" action="{{ route('reports.dispatch-cost.index') }}" class="dcr-filters">
<label>Dispatch from<input name="from" type="date" value="{{ $from }}" required></label>
<label>Dispatch to<input name="to" type="date" value="{{ $to }}" required></label>
<button class="btn" type="submit">Apply</button>
<a class="btn btn-light" href="{{ route('reports.dispatch-cost.index') }}">Reset</a>
</form>
<div class="dcr-summary">
<div><span>Source-estimated lines (current page)</span><h2>{{ number_format($summary['costed_lines']) }}</h2></div>
<div><span>Blocked lines (current page)</span><h2>{{ number_format($summary['blocked_lines']) }}</h2></div>
@foreach($summary['by_currency'] as $currency)
<div><span>{{ $currency['currency'] }} estimated source cost, current page</span>
<h2>{{ \App\Support\ReportingLabels::decimal($currency['indicative_cost']) }}</h2>
<small>{{ $currency['packages'] }} packages</small></div>
@endforeach
</div>
<p class="muted">Amounts above summarize only the current 30-row page and are grouped by original source currency. The CSV includes all matching dispatch lines.</p>
</section>
<section class="dcr-sheet">
<div class="dcr-scroll"><table>
<thead><tr>
<th>Dispatch date</th><th>Dispatch</th><th>Batch / product</th>
<th>Packages</th><th>Currency</th><th>Source cost per package</th>
<th>Indicative dispatched cost</th><th>Readiness</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td>{{ $row['date'] }}</td>
<td>{{ $row['reference'] }}</td>
<td><strong>{{ $row['batch'] }}</strong><div class="muted">{{ $row['product'] }}</div></td>
<td>{{ number_format($row['packages']) }}</td>
<td>{{ $row['currency']??'Unknown' }}</td>
<td>{{ $row['unit_cost']??'Unvalued' }}</td>
<td>{{ $row['indicative_cost']??'Not calculable' }}</td>
<td>
@if($row['status']==='blocked')
<span class="dcr-bad">Blocked</span>
@foreach($row['reasons'] as $reason)<div class="muted">{{ $reason }}</div>@endforeach
@else <span class="dcr-est">Source cost only — GL not reconciled</span>
@endif
</td>
</tr>
@empty<tr><td colspan="8">No finished-goods dispatches match these dates.</td></tr>
@endforelse
</tbody></table></div>
{{ $records->links() }}
</section>
@endsection
