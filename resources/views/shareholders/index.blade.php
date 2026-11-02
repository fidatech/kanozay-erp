@extends('layouts.kanozay')
@section('content')
<style>
.sh-layout{display:grid;grid-template-columns:minmax(280px,350px) minmax(0,1fr);gap:18px}.sh-form{display:grid;gap:12px}.sh-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#2a456d}.sh-form input,.sh-form select,.sh-form textarea{width:100%;min-height:42px;border:1px solid #d7e3f1;border-radius:9px;padding:9px 12px;background:#fff}.sh-note{font-size:12px;color:#71819c;line-height:1.55}.sh-actions{display:flex;gap:8px;flex-wrap:wrap}.sh-actions a{color:#1769b2;font-weight:700}.sh-tag{display:inline-block;padding:4px 8px;border-radius:99px;background:#edf5ff;color:#235c96;font-size:11px}@media(max-width:900px){.sh-layout{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-people-group" style="color:#6844ab"></i> Shareholders</h1><p>Shareholder profiles, capital contributions, withdrawals, and currency-separated statements.</p></div></div>
@if(session('status'))<div class="panel" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="panel" role="alert" style="color:#b42318">{{ $errors->first() }}</div>@endif
<div class="sh-layout">
 <section class="panel"><h2>Add Shareholder</h2><p class="sh-note">Ownership percentages and profit-sharing rules are not collected or inferred here. Select the currencies in which this shareholder may transact.</p>
 @if(auth()->user()->canAny(['shareholders.create','administration.update']))
 <form class="sh-form" data-new-entry-form="1" method="POST" action="{{ route('shareholders.store') }}">@csrf
  <label>Name<input name="name" maxlength="180" value="{{ old('name') }}" required></label>
  <label>Code <span class="sh-note">Optional</span><input name="code" maxlength="40" value="{{ old('code') }}"></label>
  <label>Phone<input name="phone" maxlength="80" value="{{ old('phone') }}"></label>
  <label>Email<input type="email" name="email" maxlength="180" value="{{ old('email') }}"></label>
  <label>Address<textarea name="address" maxlength="2000">{{ old('address') }}</textarea></label>
  <label>Enabled Currencies<select name="currency_ids[]" multiple required>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(in_array($currency->id, old('currency_ids', [])))>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
  <label>Notes<textarea name="notes" maxlength="2000">{{ old('notes') }}</textarea></label>
  <button class="btn" type="submit">Create Shareholder</button>
 </form>
 @else <p class="sh-note">You have read access to shareholder profiles.</p>@endif
 </section>
 <section class="panel"><h2>Shareholder Register</h2><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Currencies</th><th>Transactions</th><th>Status</th><th></th></tr></thead><tbody>
 @forelse($shareholders as $shareholder)<tr><td>{{ $shareholder->code ?: '—' }}</td><td><strong>{{ $shareholder->name }}</strong><div class="sh-note">{{ $shareholder->phone }}</div></td><td>@foreach($shareholder->currencies as $currency)<span class="sh-tag">{{ $currency->code }}</span> @endforeach</td><td>{{ number_format($shareholder->transactions_count) }}</td><td>{{ $shareholder->is_active ? 'Active' : 'Inactive' }}</td><td><a href="{{ route('shareholders.show', $shareholder) }}">Open statement</a></td></tr>
 @empty<tr><td colspan="6"><div class="k-empty-state"><strong>No shareholders registered</strong><p>Add a shareholder profile to start recording approved contributions and withdrawals.</p></div></td></tr>@endforelse
 </tbody></table></div>{{ $shareholders->onEachSide(1)->links('vendor.pagination.kanozay') }}</section>
</div>
@endsection
