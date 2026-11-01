@extends('layouts.kanozay')
@section('content')
<div class="heading">
    <div><h1><i class="fa-solid fa-chart-line"></i> Profit and Loss</h1><p>Income and expense account activity from posted journals for {{ $from }} to {{ $to }}. Currencies stay separate.</p></div>
    <div class="actions"><a class="btn btn-light" href="{{ route('finance.profit-and-loss.index', array_filter(['from'=>$from,'to'=>$to,'currency_id'=>$currency?->id,'export'=>'csv'])) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
</div>
<section class="panel k-collapsible-filters" style="padding:16px;margin-bottom:16px">
    <form method="GET" action="{{ route('finance.profit-and-loss.index') }}" class="report-filter-form">
        <label>From<input type="date" name="from" value="{{ $from }}" required></label>
        <label>To<input type="date" name="to" value="{{ $to }}" required></label>
        <label>Currency<select name="currency_id"><option value="">All currencies separately</option>@foreach($currenciesForFilter as $item)<option value="{{ $item->id }}" @selected($currency?->id===$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></label>
        <div class="k-filter-actions"><a class="btn btn-light" href="{{ route('finance.profit-and-loss.index') }}">Reset</a><button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
    </form>
</section>
@foreach($totals as $currencyId => $total)
    @php($currencyRow = $currencies->get($currencyId))
    <section class="panel report-panel" style="padding:0;overflow:hidden;margin-bottom:16px">
        <div class="report-section-head" style="padding:16px 20px"><div><h2>{{ $currencyRow?->code ?? 'Unknown currency' }} Income Statement</h2><p>Posted activity for the selected date range.</p></div><span class="report-status {{ $total['profit']->compareTo(\Brick\Math\BigDecimal::zero())>=0 ? 'is-current' : 'is-overdue' }}">{{ $total['profit']->compareTo(\Brick\Math\BigDecimal::zero())>=0 ? 'Net income' : 'Net loss' }}</span></div>
        <div class="table-wrap"><table><thead><tr><th>Account</th><th>Category</th><th>Amount ({{ $currencyRow?->code }})</th></tr></thead><tbody>
            @foreach(['income'=>'Income','expense'=>'Expenses'] as $type=>$label)
                @foreach($rows as $row)
                    @if($row['currency_id']===$currencyId && $row['type']===$type)
                        <tr><td><strong>{{ $row['code'] }}</strong> — {{ $row['name'] }}</td><td>{{ $label }}</td><td><x-finance-amount :amount="$row['amount']" /></td></tr>
                    @endif
                @endforeach
                <tr><th colspan="2">Total {{ $label }}</th><th><x-finance-amount :amount="(string)$total[$type]" /></th></tr>
            @endforeach
            <tr><th colspan="2">Net profit / (loss)</th><th><strong><x-finance-amount :amount="(string)$total['profit']" /></strong></th></tr>
        </tbody></table></div>
    </section>
@endforeach
@if(count($rows)===0)
    <section class="panel"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-chart-line"></i></div><strong>No posted income or expense activity</strong><p>Posted income and expense journals in the selected date range will appear here.</p></div></section>
@endif
@endsection
