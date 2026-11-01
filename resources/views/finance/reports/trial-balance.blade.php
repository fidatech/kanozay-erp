@extends('layouts.kanozay')
@section('content')
<div class="heading">
    <div><h1><i class="fa-solid fa-scale-balanced"></i> Trial Balance</h1><p>Posted account balances as of {{ $asOf }}. Each currency is shown separately.</p></div>
    <div class="actions"><a class="btn btn-light" href="{{ route('finance.trial-balance.index', array_filter(['as_of' => $asOf, 'currency_id' => $currency?->id, 'export' => 'csv'])) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
</div>
<section class="panel k-collapsible-filters" style="padding:16px;margin-bottom:16px">
    <form method="GET" action="{{ route('finance.trial-balance.index') }}" class="report-filter-form">
        <label>As of date<input type="date" name="as_of" value="{{ $asOf }}" required></label>
        <label>Currency<select name="currency_id"><option value="">All currencies separately</option>@foreach($currenciesForFilter as $item)<option value="{{ $item->id }}" @selected($currency?->id===$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></label>
        <div class="k-filter-actions"><a class="btn btn-light" href="{{ route('finance.trial-balance.index') }}">Reset</a><button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
    </form>
</section>
@foreach($totals as $currencyId => $total)
    @php($currencyRow = $currencies->get($currencyId))
    @php($difference = \Brick\Math\BigDecimal::of((string)$total['debit'])->minus((string)$total['credit']))
    <section class="panel report-panel" style="padding:0;overflow:hidden;margin-bottom:16px">
        <div class="report-section-head" style="padding:16px 20px"><div><h2>{{ $currencyRow?->code ?? 'Unknown currency' }} Trial Balance</h2><p>Closing debit and credit balances for posted ledger accounts.</p></div><span class="report-status {{ $difference->compareTo(\Brick\Math\BigDecimal::zero())===0 ? 'is-current' : 'is-overdue' }}">{{ $difference->compareTo(\Brick\Math\BigDecimal::zero())===0 ? 'Balanced' : 'Out of balance' }}</span></div>
        <div class="table-wrap"><table><thead><tr><th>Account</th><th>Type</th><th>Debit balance ({{ $currencyRow?->code }})</th><th>Credit balance ({{ $currencyRow?->code }})</th></tr></thead><tbody>
            @foreach($rows as $row)
                @if($row['currency_id']===$currencyId)
                    <tr><td><strong>{{ $row['code'] }}</strong> — {{ $row['name'] }}</td><td>{{ ucfirst($row['type']) }}</td><td><x-finance-amount :amount="$row['debit_balance']" /></td><td><x-finance-amount :amount="$row['credit_balance']" /></td></tr>
                @endif
            @endforeach
            <tr><th colspan="2">Total</th><th><x-finance-amount :amount="(string)$total['debit']" /></th><th><x-finance-amount :amount="(string)$total['credit']" /></th></tr>
        </tbody></table></div>
    </section>
@endforeach
@if(count($rows)===0)
    <section class="panel"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-scale-balanced"></i></div><strong>No posted balances found</strong><p>Posted journal entries through the selected date will appear here.</p></div></section>
@endif
@endsection
