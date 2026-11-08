@extends('layouts.kanozay')
@section('content')
<style>
.mfr-panel{padding:18px;background:#fff;border:1px solid #dce6f0;border-radius:11px;margin:15px 0}
.mfr-table{overflow-x:auto}.mfr-table table{min-width:1360px;font-size:12px}
.mfr-table th,.mfr-table td{padding:11px;vertical-align:top;text-align:start}
.mfr-alert{padding:13px;border:1px solid #ead5b4;border-radius:9px;background:#fff7ec;font-size:13px;line-height:1.7}
.mfr-good{color:#17724d;font-weight:650}.mfr-bad{color:#b34632;font-weight:650}
.mfr-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.mfr-filters label{display:grid;gap:5px}
</style>
<div class="heading"><div><h1>Manufacturing Inventory, COGS and Non-sale Expense Reconciliation</h1>
<p>Compare physical finished batches, QC returns and original-currency GL inventory journals.</p>
</div><div class="actions">
<a class="btn btn-light" href="{{ route('manufacturing.cost-accounting.index') }}">Manufacturing cost accounting</a>
<a class="btn btn-light" href="{{ route('finance.historical-corrections.index') }}">Historical source corrections</a>
<a class="btn" href="{{ route('reports.manufacturing-financial.index',array_merge(request()->query(),['export'=>'csv'])) }}">Export all matching CSV</a>
</div></div>
<p class="mfr-alert"><strong>Review before financial close.</strong>
This register compares posted WIP/finished-stock capitalization journals, genuine original-currency sales COGS, independently authorized sample/loss/internal-use inventory expenses and returned-goods cost recoveries against physical batch quantities. It highlights missing or reversed source journals and legacy stock mismatches. Amounts are shown by batch and currency without FX conversion.
The report cannot establish historical inventory value from unrecorded invoices or correct old discrepancies automatically.
</p>
<section class="mfr-panel">
<form method="GET" action="{{ route('reports.manufacturing-financial.index') }}" class="mfr-filters">
<label>Batch status<select name="status">
<option value="">All recorded batches</option>
@foreach(['released'=>'Released','qc_approved'=>'Approved pending warehouse release','qc_rejected'=>'Rejected','pending_qc'=>'Pending QC'] as $status=>$label)
<option value="{{ $status }}" @selected(request('status')===$status)>{{ $label }}</option>
@endforeach
</select></label>
<button class="btn" type="submit">Filter</button>
<a class="btn btn-light" href="{{ route('reports.manufacturing-financial.index') }}">Reset</a>
</form>
<p class="muted">{{ number_format($rows->count()) }} batches shown on the current page.
@if($exceptions)<strong style="color:#a74331">{{ number_format($exceptions) }} have audit exceptions.</strong>
@else No automated reconciliation exceptions on this page. @endif
The CSV includes all matching batches and their individual exceptions.</p>
</section>
<section class="mfr-panel">
<div class="mfr-table"><table>
<thead><tr>
<th>Production batch</th><th>Currency</th>
<th>QC accepted / warehouse received / dispatched / QC returned</th>
<th>Physical remaining</th>
<th>Costed sales / non-sale / QC recoveries</th><th>Costed remaining</th>
<th>Gross source materials</th><th>Abnormal FIFO loss expense</th>
<th>Net capitalized inventory</th><th>Recognized COGS</th><th>Non-sale inventory expense</th>
<th>Return recovery</th><th>Remaining asset value</th><th>Audit result</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><strong>{{ $row['batch'] }}</strong><div>{{ $row['product'] }}</div>
<small>{{ ucwords(str_replace('_',' ',$row['status'])) }}</small></td>
<td>{{ $row['currency']??'Unreconciled' }}</td>
<td>{{ number_format($row['accepted']) }} /
{{ number_format($row['received']) }} /
{{ number_format($row['dispatched']) }} /
{{ number_format($row['qc_returns']) }}</td>
<td>{{ number_format($row['net_physical']) }}</td>
<td>{{ number_format($row['costed_sales']) }} /
{{ number_format($row['costed_non_sale']) }} /
{{ number_format($row['costed_recoveries']) }}</td>
<td>@if($row['remaining_costed']!==null){{ number_format($row['remaining_costed']) }}@else<span class="muted">Not capitalized</span>@endif</td>
<td>{{ $row['gross_material_source_cost']??'Unverified' }}</td>
<td>{{ $row['abnormal_waste_expensed']??'Unverified' }}</td>
<td>{{ $row['total_capitalized']??'Not capitalized' }}</td>
<td>{{ $row['posted_cogs']??'—' }}</td>
<td>{{ $row['posted_non_sale_expense']??'—' }}</td>
<td>{{ $row['returned_cost']??'—' }}</td>
<td>{{ $row['remaining_asset_value']??'Unverified' }}</td>
<td>
@if($row['issues'])
<strong class="mfr-bad">Requires reconciliation</strong>
@foreach($row['issues'] as $issue)
<div style="max-width:280px;margin-top:6px">{{ $issue }}</div>
@endforeach
@elseif($row['has_capitalization'])
<strong class="mfr-good">Recorded quantities and source journals agree</strong>
@else
<span class="muted">No financial capitalization recorded; no original invoice dispatch identified</span>
@endif
</td></tr>
@empty<tr><td colspan="14">No production batches match the selected filter.</td></tr>
@endforelse
</tbody></table></div>
{{ $batches->links() }}
<p class="muted">A matching register is a source-accounting integrity check only. It does not certify paid purchases, complete landed costs, tax treatments, inventory opening balances or consolidated financial statements.</p>
</section>
@endsection
