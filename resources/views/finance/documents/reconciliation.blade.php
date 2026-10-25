@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1><i class="fa-solid fa-scale-balanced"></i> Settlement Reconciliation</h1><p>Customer and supplier outstanding documents compared with unapplied payments, by currency.</p></div></div>
<section class="panel">
<form method="GET" action="{{ route('finance.reconciliation.index') }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
<label>Account Type<select name="type"><option value="receivable" @selected($type==='receivable')>Customer Receivables</option><option value="payable" @selected($type==='payable')>Supplier Payables</option></select></label>
<button class="btn" type="submit">Refresh Report</button>
<a class="btn" href="{{ route('finance.documents.index',['type'=>$type]) }}">Documents</a>
</form>
</section>
<section class="panel"><h2>Party Reconciliation</h2><p style="color:#64748b;font-size:12px">Outstanding documents and unapplied payments are separate amounts; their difference is not automatically an accounting discrepancy. Issues indicate an allocation exceeding its source amount.</p>
<div class="table-wrap"><table><thead><tr><th>Customer / Supplier</th><th>Currency</th><th>Outstanding Documents</th><th>Unapplied Payments</th><th>Net Exposure</th><th>Allocation Issues</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td><strong>{{ $row['party'] }}</strong></td><td>{{ $currencies[$row['currency']]??'—' }}</td>
<td><x-finance-amount :amount="$row['outstanding']" :direction="$type==='receivable'?'in':'out'" /></td>
<td><x-finance-amount :amount="$row['unapplied']" /></td>
<td><x-finance-amount :amount="$row['outstanding']-$row['unapplied']" /></td>
<td>@if($row['issues'])<strong style="color:#b42318">{{ $row['issues'] }} issue(s)</strong>@else<span style="color:#15803d">No excess allocations</span>@endif</td></tr>
@empty<tr><td colspan="6">No trade balances found.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
