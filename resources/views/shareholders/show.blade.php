@extends('layouts.kanozay')
@section('content')
<style>.sh-grid{display:grid;grid-template-columns:minmax(270px,360px) minmax(0,1fr);gap:18px}.sh-form{display:grid;gap:11px}.sh-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#2a456d}.sh-form input,.sh-form select,.sh-form textarea{width:100%;min-height:41px;border:1px solid #d7e3f1;border-radius:9px;padding:9px 11px;background:#fff}.sh-note{font-size:12px;color:#71819c;line-height:1.5}.sh-metric{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sh-chip{padding:5px 8px;border-radius:8px;background:#f1f6fc;color:#244b76;font-size:12px}.sh-filter{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.sh-filter input,.sh-filter select{min-height:36px;border:1px solid #d7e3f1;border-radius:8px;padding:6px 9px}@media(max-width:950px){.sh-grid{grid-template-columns:1fr}}</style>
<div class="heading"><div><h1><i class="fa-solid fa-user-group" style="color:#6844ab"></i> {{ $shareholder->name }}</h1><p>Shareholder account and posted capital activity</p></div><a class="btn" href="{{ route('shareholders.index') }}">Shareholder Register</a></div>
@if(session('status'))<div class="panel" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="panel" role="alert" style="color:#b42318">{{ $errors->first() }}</div>@endif
<div class="sh-grid">
 <section class="panel"><h2>Post contribution or authorized capital drawing</h2><p class="sh-note">Posting creates a balanced journal and a cashbook transaction. Contributions increase paid-in capital; withdrawals post to the separately mapped drawings ledger and cannot exceed posted capital less earlier drawings in the same currency as of the transaction date. Financial authorization is required. Ownership percentages and profit-distribution entitlements remain separate.</p>
 @if(auth()->user()->can('administration.update') || (auth()->user()->canAny(['shareholders.create','shareholders.update']) && auth()->user()->can('finance.update')))
 <form class="sh-form" data-new-entry-form="1" method="POST" action="{{ route('shareholders.transactions.store', $shareholder) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
  <label>Transaction Type<select name="type" required><option value="contribution">Capital Contribution</option><option value="withdrawal">Capital Withdrawal</option></select></label>
  <label>Date<input type="date" name="transaction_date" value="{{ now()->toDateString() }}" required></label>
  <label>Currency<select name="currency_id" required>@foreach($shareholder->currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
  <label>Cash / Bank / Sarafi Account<select name="financial_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}" data-currency="{{ $account->currency_id }}">{{ $account->name }} — {{ $account->currency?->code }}</option>@endforeach</select></label>
  <label>Amount<input name="amount" inputmode="decimal" maxlength="24" required></label>
  <label>Authorization / transaction reference<input name="reference" minlength="4" maxlength="120" placeholder="Required for capital drawings"></label>
  <label>Description<textarea name="description" maxlength="2000" required></textarea></label>
  <button class="btn" type="submit">Post Transaction</button>
 </form>
 @else<p class="sh-note">Your role can view this statement but cannot post transactions.</p>@endif
 </section>
 <section class="panel"><h2>Shareholder capital and profit statement</h2>
<p class="sh-note">Amounts below are posted activity in the selected date range. Each currency is separate. Contributions less drawings is a movement summary, not legal ownership, a reconciled total net-equity balance, or a currency-converted company valuation. Profit distributions are reported separately and do not increase capital.</p>
<div class="sh-metric">@foreach($shareholder->currencies as $currency)<span class="sh-chip">{{ $currency->code }} ledger</span>@endforeach</div>
 <form class="sh-filter" method="GET"><select name="currency_id"><option value="">All currencies</option>@foreach($shareholder->currencies as $currency)<option value="{{ $currency->id }}" @selected(($filters['currency_id'] ?? '') == $currency->id)>{{ $currency->code }}</option>@endforeach</select><input type="date" name="from" value="{{ $filters['from'] ?? '' }}"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}"><button class="btn" type="submit">Filter</button><a class="btn" href="{{ route('shareholders.show', array_merge([$shareholder], request()->query(), ['export'=>'csv'])) }}">CSV</a></form>
<div class="table-wrap" style="margin:12px 0">
<table><thead><tr><th>Currency</th><th>Capital contributions</th><th>Capital drawings</th><th>Net capital activity</th><th>Profit payouts</th></tr></thead><tbody>
@foreach($statementTotals as $row)
<tr><td>{{ $row['currency_code'] }}</td>
<td><x-finance-amount :amount="$row['contribution']" /></td>
<td><x-finance-amount :amount="$row['withdrawal']" /></td>
<td><x-finance-amount :amount="$row['net_capital_activity']" /></td>
<td><x-finance-amount :amount="$row['distribution']" /></td>
</tr>
@endforeach
</tbody></table></div>
<h3>Combined posted activity</h3>
<div class="table-wrap"><table>
<thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Currency</th><th>Amount</th><th>Source/board reference</th><th>Journal</th></tr></thead>
<tbody>
@forelse($recentActivity as $entry)
<tr><td>{{ $entry['occurred_on'] }}</td><td>{{ $entry['number'] }}</td>
<td>{{ ucwords(str_replace('_',' ',$entry['type'])) }}</td>
<td>{{ $entry['currency_code'] }}</td>
<td><x-finance-amount :amount="$entry['amount']" /></td>
<td>{{ $entry['reference']?:'—' }}</td><td>{{ $entry['journal_number'] }}</td></tr>
@empty<tr><td colspan="7">No posted capital, drawing or profit distribution activity matches this filter.</td></tr>
@endforelse
</tbody></table></div>
<p class="sh-note">Showing the latest {{ count($recentActivity) }} of {{ $activityCount }} posted activities for these filters. The CSV contains all matching source transactions.</p>
<h3>Capital transaction register</h3>
 <div class="table-wrap"><table><thead><tr><th>Date</th><th>Number</th><th>Type</th><th>Account</th><th>Currency</th><th>Amount</th><th>Journal</th></tr></thead><tbody>
 @forelse($transactions as $transaction)<tr><td>{{ $transaction->transaction_date->toDateString() }}</td><td>{{ $transaction->number }}</td><td>{{ ucfirst($transaction->type) }}</td><td>{{ $transaction->financialAccount?->name }}</td><td>{{ $transaction->currency?->code }}</td><td><x-finance-amount :amount="$transaction->amount" /></td><td>{{ $transaction->journal?->number }}</td></tr>@empty<tr><td colspan="7"><div class="k-empty-state"><strong>No posted shareholder activity</strong><p>Transactions are listed after they post to the General Ledger.</p></div></td></tr>@endforelse
 </tbody></table></div>{{ $transactions->onEachSide(1)->links('vendor.pagination.kanozay') }}</section>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const form=document.querySelector('.sh-form');if(!form)return;const currency=form.querySelector('[name="currency_id"]');const account=form.querySelector('[name="financial_account_id"]');const sync=()=>{const selected=currency.value;Array.from(account.options).forEach(option=>{option.hidden=option.dataset.currency!==selected;});if(account.selectedOptions[0]?.dataset.currency!==selected)account.value=Array.from(account.options).find(option=>option.dataset.currency===selected)?.value||'';};currency.addEventListener('change',sync);sync();});
</script>
@endsection
