@extends('layouts.kanozay')
@section('content')
<div class="heading">
    <div><h1><i class="fa-solid fa-money-bill-transfer"></i> Cash, Bank and Sarafi Movements</h1><p>Posted ledger movements by financial account from {{ $from }} to {{ $to }}. Currency totals are kept separate; this report does not assign cash-flow categories.</p></div>
    <div class="actions"><a class="btn btn-light" href="{{ route('finance.cash-movements.index', array_filter(['from'=>$from,'to'=>$to,'currency_id'=>$currencyId,'type'=>$type,'export'=>'csv'])) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
</div>
<section class="panel k-collapsible-filters" style="padding:16px;margin-bottom:16px">
    <form method="GET" action="{{ route('finance.cash-movements.index') }}" class="report-filter-form">
        <label>From<input type="date" name="from" value="{{ $from }}" required></label>
        <label>To<input type="date" name="to" value="{{ $to }}" required></label>
        <label>Account type<select name="type"><option value="">All accounts</option>@foreach(['cashbox'=>'Cash','bank'=>'Bank','sarafi'=>'Sarafi'] as $key=>$label)<option value="{{ $key }}" @selected($type===$key)>{{ $label }}</option>@endforeach</select></label>
        <label>Currency<select name="currency_id"><option value="">All currencies separately</option>@foreach($currencies as $item)<option value="{{ $item->id }}" @selected((string)$currencyId===(string)$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></label>
        <div class="k-filter-actions"><a class="btn btn-light" href="{{ route('finance.cash-movements.index') }}">Reset</a><button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
    </form>
</section>
<section class="panel report-panel" style="padding:0;overflow:hidden">
    <div class="report-section-head" style="padding:16px 20px"><div><h2>Financial account activity</h2><p>Opening balances include posted entries before the selected start date.</p></div><span class="report-count">{{ count($rows) }} accounts</span></div>
    <div class="table-wrap"><table><thead><tr><th>Account</th><th>Type</th><th>Currency</th><th>Opening balance</th><th>Inflows</th><th>Outflows</th><th>Closing balance</th><th>Ledger status</th></tr></thead><tbody>
        @forelse($rows as $row)
            <tr><td><strong>{{ $row['account']->name }}</strong><br><small>{{ $row['account']->institution }}</small></td><td>{{ ucfirst($row['account']->type) }}</td><td>{{ $row['currency']?->code }}</td><td><x-finance-amount :amount="$row['opening']" /></td><td><x-finance-amount :amount="$row['inflows']" /></td><td><x-finance-amount :amount="$row['outflows']" /></td><td><strong><x-finance-amount :amount="$row['closing']" /></strong></td><td>@if($row['mapped'])<span class="report-status is-current">Mapped</span>@else<span class="report-status is-overdue">Missing ledger mapping</span>@endif</td></tr>
        @empty
            <tr><td colspan="8"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-building-columns"></i></div><strong>No financial accounts found</strong><p>Create a cash, bank, or sarafi account to view posted movements.</p></div></td></tr>
        @endforelse
    </tbody></table></div>
</section>
@endsection
