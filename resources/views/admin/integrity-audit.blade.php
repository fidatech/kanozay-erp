@extends('layouts.kanozay')
@section('content')
<style>
.ia-card{background:#fff;border:1px solid #dce6f2;border-radius:12px;padding:21px;margin:16px 0}
.ia-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ia-metrics div{background:#f4f8fc;border-radius:9px;padding:14px}.ia-metrics strong{display:block;font-size:20px}
.ia-scroll{overflow:auto}.ia-card table{min-width:800px;font-size:12px}
@media(max-width:800px){.ia-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="heading"><div><h1>ERP Cross-Module Integrity Review</h1><p>Read-only database diagnostics for Finance, Purchasing, Inventory, Production, Sales and Payroll</p></div>
<a href="{{ route('admin.integrity-audit.export') }}" class="btn btn-light">Export findings CSV</a></div>
<section class="ia-card"><h2>Checked at {{ $result['checked_at'] }}</h2>
<div class="ia-metrics">
@foreach($result['inspected'] as $module=>$count)<div><small>{{ ucwords(str_replace('_',' ',$module)) }}</small><strong>{{ number_format($count) }}</strong></div>@endforeach
</div><p class="muted">This audit does not change records or calculate unsupported multi-currency totals. Zero findings cannot certify the original source workbook, tax policy, costing completeness, sales delivery, physical counts or UAT readiness. A warning means data should be investigated, not overwritten.</p>
@if($result['capped'])<p class="notice notice-warning">Finding display limit reached. Investigate the source records and rerun the review.</p>@endif
</section>
<section class="ia-card"><h2>Flagged inconsistencies: {{ $result['count'] }}{{ $result['capped']?'+':'' }}</h2>
<div class="ia-scroll"><table><thead><tr><th>Module</th><th>Severity</th><th>Source record</th><th>Diagnostic</th></tr></thead><tbody>
@forelse($result['issues'] as $issue)<tr><td>{{ $issue['module'] }}</td><td>{{ strtoupper($issue['level']) }}</td><td>{{ $issue['subject'] }}</td><td>{{ $issue['message'] }}</td></tr>
@empty<tr><td colspan="4">No inconsistencies found by these specific read-only checks. Other business controls still need acceptance and source-data verification.</td></tr>@endforelse
</tbody></table></div></section>
@endsection