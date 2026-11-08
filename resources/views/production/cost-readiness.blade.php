@extends('layouts.kanozay')
@section('content')
<style>
.cr-panel{padding:20px;border-radius:11px;background:#fff;border:1px solid #deebf5;margin:15px 0}
.cr-table{overflow:auto}.cr-table table{min-width:1050px;font-size:12px}
.cr-table th,.cr-table td{padding:12px;vertical-align:top}
.cr-limit{border-radius:8px;background:#fff7e8;padding:14px;color:#7f5c25;line-height:1.6}
.cr-ok{color:#087452}.cr-block{color:#a14b33}
</style>
<div class="heading"><div><h1>Production Batch Cost Readiness</h1><p>Source-currency material cost, approved overhead, outstanding gaps and indicative finished package cost</p></div>
<div class="actions"><a class="btn btn-light" href="{{ route('workspace.page',['manufacturing','costing']) }}">Cost allocation register</a>
<a class="btn btn-light" href="{{ route('manufacturing.cost-accounting.index') }}">Manufacturing GL cost accounting</a>
<a class="btn" href="{{ route('manufacturing.cost-readiness.export',request()->query()) }}">Export CSV</a></div></div>
<p class="cr-limit">This view distinguishes advisory source-cost readiness from actual posted manufacturing inventory capitalization. Finance can open the linked accounting workflow to verify the original supplier inventory asset and approved overhead GL sources before capitalization. Unvalued source materials, missing batch snapshots, mixed currencies and pending overhead block a complete cost per package. Amounts from different currencies are never summed together. A ready source cost requires accounting reconciliation before production capitalization.</p>
<section class="cr-panel"><form method="GET" action="{{ route('manufacturing.cost-readiness.index') }}" style="display:flex;gap:10px;align-items:center">
<select name="status"><option value="">All batch statuses</option>
@foreach(['pending_qc'=>'Pending laboratory QC','qc_approved'=>'Approved for release','released'=>'Released','qc_rejected'=>'Rejected'] as $status=>$label)
<option value="{{ $status }}" @selected(request('status')===$status)>{{ $label }}</option>@endforeach
</select><button class="btn btn-light">Filter</button></form></section>
<section class="cr-panel"><div class="cr-table"><table><thead><tr><th>Production batch</th><th>Finished good</th><th>Status</th><th>Accepted packages</th><th>Source currency</th><th>Material cost</th><th>Approved overhead</th><th>Overhead pending</th><th>Cost per package</th><th>Finished-goods GL</th><th>Readiness</th></tr></thead><tbody>
@forelse($rows as $row)<tr>
<td><strong>{{ $row['batch'] }}</strong></td><td>{{ $row['product'] }}</td>
<td>{{ ucwords(str_replace('_',' ',$row['status'])) }}</td><td>{{ $row['packages'] }}</td>
<td>{{ $row['currency'] }}</td><td>{{ $row['material_cost']??'Unvalued' }}</td>
<td>{{ $row['approved_overhead'] }}</td><td>{{ $row['pending_overheads'] }}</td>
<td>{{ $row['cost_per_package']??'Not calculable' }}</td>
<td>@if($row['gl_posted'])<strong class="cr-ok">Posted</strong>
<div>{{ $row['gl_accounted_cost'] }} in source currency</div>
@else<span class="cr-block">Not posted</span>@endif</td>
<td class="{{ $row['gl_posted']?'cr-ok':'cr-block' }}">{{ $row['warning'] }}</td></tr>
@empty<tr><td colspan="11">No production batches recorded.</td></tr>@endforelse
</tbody></table></div>{{ $batches->links() }}</section>
@endsection