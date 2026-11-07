@extends('layouts.kanozay')
@section('content')
<style>.pc-panel{padding:20px;background:#fff;border:1px solid #dce7f1;border-radius:10px;margin:15px 0}
.pc-wrap{overflow-x:auto}.pc-panel table{min-width:810px;font-size:12px}
.pc-panel td,.pc-panel th{padding:11px}</style>
<div class="heading"><div><h1>Payroll Components</h1><p>Audit history for reviewed salary allowances and deductions</p></div><a class="btn btn-light" href="{{ route('workspace.page',['hr','payroll']) }}">Payroll periods</a></div>
<div class="pc-panel"><h2>Itemized earnings and withholdings</h2>
<p class="muted">No statutory tax rate is assumed. Payroll components are entered with explicit policy/approval references and mapped expense/liability ledgers. Draft periods cannot be paid before finance approval.</p>
<div class="pc-wrap"><table><thead><tr><th>Period</th><th>Employee</th><th>Category</th><th>Description</th><th>Amount</th><th>Ledger</th><th>Approval reference</th></tr></thead><tbody>
@forelse($components as $c)
<tr><td><a href="{{ route('hr.payroll.show',$c->line?->period?->id) }}">{{ $c->line?->period?->period_start?->format('Y-m') }}</a></td>
<td>{{ $c->line?->employee?->employee_number }}</td><td>{{ ucfirst($c->kind) }}</td><td>{{ $c->label }}</td>
<td>{{ $c->amount }} {{ $c->line?->period?->currency?->code }}</td>
<td>{{ $c->ledger?->code }} {{ $c->ledger?->name }}</td><td>{{ $c->approval_reference }}</td></tr>
@empty<tr><td colspan="7">No itemized payroll components yet.</td></tr>@endforelse
</tbody></table></div>{{ $components->links() }}</div>
<div class="pc-panel"><h2>Review a draft payroll period</h2>
@foreach($periods as $p)<p><a href="{{ route('hr.payroll.show',$p) }}">{{ $p->period_start?->format('Y-m') }} · {{ $p->currency?->code }} · {{ ucfirst($p->status) }}</a></p>@endforeach
</div>
@endsection
