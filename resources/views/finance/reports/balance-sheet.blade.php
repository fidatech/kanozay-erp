@extends('layouts.kanozay')
@section('content')
<div class="heading">
    <div><h1><i class="fa-solid fa-building-columns"></i> Balance Sheet</h1><p>Posted account balances as of {{ $asOf }}. Current earnings use the configured fiscal year starting {{ $fiscalYearStart }}. Currencies stay separate.</p></div>
    <div class="actions"><a class="btn btn-light" href="{{ route('finance.balance-sheet.index', array_filter(['as_of'=>$asOf,'currency_id'=>$currency?->id,'export'=>'csv'])) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
</div>
<section class="panel k-collapsible-filters" style="padding:16px;margin-bottom:16px">
    <form method="GET" action="{{ route('finance.balance-sheet.index') }}" class="report-filter-form">
        <label>As of date<input type="date" name="as_of" value="{{ $asOf }}" required></label>
        <label>Currency<select name="currency_id"><option value="">All currencies separately</option>@foreach($currenciesForFilter as $item)<option value="{{ $item->id }}" @selected($currency?->id===$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></label>
        <div class="k-filter-actions"><a class="btn btn-light" href="{{ route('finance.balance-sheet.index') }}">Reset</a><button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
    </form>
</section>
@foreach($totals as $currencyId => $total)
    @php($currencyRow = $currencies->get($currencyId))
    <section class="panel report-panel" style="padding:0;overflow:hidden;margin-bottom:16px">
        <div class="report-section-head" style="padding:16px 20px"><div><h2>{{ $currencyRow?->code ?? 'Unknown currency' }} Balance Sheet</h2><p>Assets compared with liabilities, equity, and current fiscal-year earnings.</p></div><span class="report-status {{ $total['difference']->compareTo(\Brick\Math\BigDecimal::zero())===0 ? 'is-current' : 'is-overdue' }}">{{ $total['difference']->compareTo(\Brick\Math\BigDecimal::zero())===0 ? 'Balanced' : 'Review difference' }}</span></div>
        <div class="table-wrap"><table><thead><tr><th>Account / section</th><th>Balance ({{ $currencyRow?->code }})</th></tr></thead><tbody>
            @foreach(['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity'] as $type=>$label)
                <tr><th colspan="2">{{ $label }}</th></tr>
                @foreach($rows as $row)
                    @if($row['currency_id']===$currencyId && $row['type']===$type)
                        <tr><td><strong>{{ $row['code'] }}</strong> — {{ $row['name'] }}</td><td><x-finance-amount :amount="$row['amount']" /></td></tr>
                    @endif
                @endforeach
                <tr><th>Total {{ $label }}</th><th><x-finance-amount :amount="(string)$total[$type]" /></th></tr>
            @endforeach
            <tr><td>Current fiscal-year earnings</td><td><x-finance-amount :amount="(string)$total['current_earnings']" /></td></tr>
            <tr><th>Total liabilities and equity</th><th><x-finance-amount :amount="(string)$total['liabilities_and_equity']" /></th></tr>
            <tr><th>Balance difference</th><th><strong><x-finance-amount :amount="(string)$total['difference']" /></strong></th></tr>
        </tbody></table></div>
    </section>
@endforeach
@if(count($rows)===0)
    <section class="panel"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-building-columns"></i></div><strong>No posted balance sheet activity</strong><p>Posted asset, liability, equity, income, or expense entries through the selected date will appear here.</p></div></section>
@endif
@endsection
