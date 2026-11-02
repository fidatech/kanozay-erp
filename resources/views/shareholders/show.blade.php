@extends('layouts.kanozay')
@section('content')
<style>.sh-grid{display:grid;grid-template-columns:minmax(270px,360px) minmax(0,1fr);gap:18px}.sh-form{display:grid;gap:11px}.sh-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#2a456d}.sh-form input,.sh-form select,.sh-form textarea{width:100%;min-height:41px;border:1px solid #d7e3f1;border-radius:9px;padding:9px 11px;background:#fff}.sh-note{font-size:12px;color:#71819c;line-height:1.5}.sh-metric{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sh-chip{padding:5px 8px;border-radius:8px;background:#f1f6fc;color:#244b76;font-size:12px}.sh-filter{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.sh-filter input,.sh-filter select{min-height:36px;border:1px solid #d7e3f1;border-radius:8px;padding:6px 9px}@media(max-width:950px){.sh-grid{grid-template-columns:1fr}}</style>
<div class="heading"><div><h1><i class="fa-solid fa-user-group" style="color:#6844ab"></i> {{ $shareholder->name }}</h1><p>Shareholder account and posted capital activity</p></div><a class="btn" href="{{ route('shareholders.index') }}">Shareholder Register</a></div>
@if(session('status'))<div class="panel" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="panel" role="alert" style="color:#b42318">{{ $errors->first() }}</div>@endif
<div class="sh-grid">
 <section class="panel"><h2>Post Contribution or Withdrawal</h2><p class="sh-note">Posting creates a balanced journal immediately. Contributions credit the shareholder capital ledger; withdrawals debit the shareholder drawings ledger. No ownership percentage or distribution rule is applied.</p>
 @if(auth()->user()->canAny(['shareholders.create','shareholders.update','administration.update']))
 <form class="sh-form" data-new-entry-form="1" method="POST" action="{{ route('shareholders.transactions.store', $shareholder) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
  <label>Transaction Type<select name="type" required><option value="contribution">Capital Contribution</option><option value="withdrawal">Capital Withdrawal</option></select></label>
  <label>Date<input type="date" name="transaction_date" value="{{ now()->toDateString() }}" required></label>
  <label>Currency<select name="currency_id" required>@foreach($shareholder->currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
  <label>Cash / Bank / Sarafi Account<select name="financial_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}" data-currency="{{ $account->currency_id }}">{{ $account->name }} — {{ $account->currency?->code }}</option>@endforeach</select></label>
  <label>Amount<input name="amount" inputmode="decimal" maxlength="24" required></label>
  <label>Reference<input name="reference" maxlength="120"></label>
  <label>Description<textarea name="description" maxlength="2000" required></textarea></label>
  <button class="btn" type="submit">Post Transaction</button>
 </form>
 @else<p class="sh-note">Your role can view this statement but cannot post transactions.</p>@endif
 </section>
 <section class="panel"><h2>Statement</h2><div class="sh-metric">@foreach($shareholder->currencies as $currency)<span class="sh-chip">{{ $currency->code }} ledger</span>@endforeach</div>
 <form class="sh-filter" method="GET"><select name="currency_id"><option value="">All currencies</option>@foreach($shareholder->currencies as $currency)<option value="{{ $currency->id }}" @selected(($filters['currency_id'] ?? '') == $currency->id)>{{ $currency->code }}</option>@endforeach</select><input type="date" name="from" value="{{ $filters['from'] ?? '' }}"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}"><button class="btn" type="submit">Filter</button><a class="btn" href="{{ route('shareholders.show', array_merge([$shareholder], request()->query(), ['export'=>'csv'])) }}">CSV</a></form>
 <div class="table-wrap"><table><thead><tr><th>Date</th><th>Number</th><th>Type</th><th>Account</th><th>Currency</th><th>Amount</th><th>Journal</th></tr></thead><tbody>
 @forelse($transactions as $transaction)<tr><td>{{ $transaction->transaction_date->toDateString() }}</td><td>{{ $transaction->number }}</td><td>{{ ucfirst($transaction->type) }}</td><td>{{ $transaction->financialAccount?->name }}</td><td>{{ $transaction->currency?->code }}</td><td><x-finance-amount :amount="$transaction->amount" /></td><td>{{ $transaction->journal?->number }}</td></tr>@empty<tr><td colspan="7"><div class="k-empty-state"><strong>No posted shareholder activity</strong><p>Transactions are listed after they post to the General Ledger.</p></div></td></tr>@endforelse
 </tbody></table></div>{{ $transactions->onEachSide(1)->links('vendor.pagination.kanozay') }}</section>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const form=document.querySelector('.sh-form');if(!form)return;const currency=form.querySelector('[name="currency_id"]');const account=form.querySelector('[name="financial_account_id"]');const sync=()=>{const selected=currency.value;Array.from(account.options).forEach(option=>{option.hidden=option.dataset.currency!==selected;});if(account.selectedOptions[0]?.dataset.currency!==selected)account.value=Array.from(account.options).find(option=>option.dataset.currency===selected)?.value||'';};currency.addEventListener('change',sync);sync();});
</script>
@endsection
