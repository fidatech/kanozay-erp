@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1><i class="fa-solid {{ $type==='customer'?'fa-users':'fa-truck-field' }}"></i> {{ $party->name }}</h1><p>{{ ucfirst($type) }} profile &amp; financial overview</p></div><div style="display:flex;gap:10px;flex-wrap:wrap">@canany(['administration.update','finance.update'])<button class="btn" type="button" onclick="document.getElementById('party-edit-dialog').showModal()">Edit Profile</button>@endcanany<a class="btn btn-light" href="{{ route($type==='customer'?'finance.customers.index':'finance.suppliers.index') }}">Back to {{ ucfirst($type) }}s</a></div></div>
<div class="panel"><h2>Contact &amp; Account Details</h2><div class="table-wrap"><table><tbody><tr><th>Code</th><td>{{ $party->code ?: '—' }}</td><th>Status</th><td>{{ $party->is_active?'Active':'Inactive' }}</td></tr><tr><th>Contact person</th><td>{{ $party->contact_person ?: '—' }}</td><th>Phone</th><td>{{ $party->phone ?: '—' }}</td></tr><tr><th>Email</th><td>{{ $party->email ?: '—' }}</td><th>Address</th><td>{{ $party->address ?: '—' }}</td></tr><tr><th>Notes</th><td colspan="3">{{ $party->notes ?: '—' }}</td></tr></tbody></table></div></div>
@canany(['administration.update','finance.update'])
<dialog id="party-edit-dialog" class="k-modal" style="width:min(720px,calc(100vw - 30px))"><div class="modal-head"><h2>Edit {{ ucfirst($type) }} Profile</h2><button type="button" class="modal-close" aria-label="Close edit profile" onclick="document.getElementById('party-edit-dialog').close()">&times;</button></div>@if($errors->any())<p style="color:#b42318;padding:12px 22px">{{ $errors->first() }}</p>@endif
<form method="POST" class="modal-form" action="{{ route($type==='customer'?'finance.customers.update':'finance.suppliers.update',$party) }}"><div class="modal-body" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">@csrf @method('PATCH')
<label>Name <input name="name" required maxlength="180" value="{{ old('name',$party->name) }}"></label>
<label>Code <input name="code" maxlength="40" value="{{ old('code',$party->code) }}"></label>
<label>Contact person <input name="contact_person" maxlength="150" value="{{ old('contact_person',$party->contact_person) }}"></label>
<label>Phone <input name="phone" maxlength="80" value="{{ old('phone',$party->phone) }}"></label>
<label>Email <input type="email" name="email" maxlength="180" value="{{ old('email',$party->email) }}"></label>
<label>Address <input name="address" maxlength="2000" value="{{ old('address',$party->address) }}"></label>
<label style="grid-column:1/-1">Notes <textarea name="notes" rows="3" maxlength="2000">{{ old('notes',$party->notes) }}</textarea></label>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="document.getElementById('party-edit-dialog').close()">Cancel</button><button type="submit" class="btn">Save Profile</button></div></form></dialog>
@if($errors->any())<script>document.addEventListener('DOMContentLoaded',function(){document.getElementById('party-edit-dialog').showModal()})</script>@endif
@endcanany
<div class="panel"><h2>Financial Snapshot</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px"><div style="padding:15px;border:1px solid #dce7f2;border-radius:12px"><small>Enabled Currencies</small><h2>{{ $party->currencies->count() }}</h2></div><div style="padding:15px;border:1px solid #dce7f2;border-radius:12px"><small>Opening Balance Records</small><h2>{{ $openingBalances->count() }}</h2></div><div style="padding:15px;border:1px solid #dce7f2;border-radius:12px"><small>Recent Financial Documents</small><h2>{{ $documents->count() }}</h2><small>Last 100 maximum</small></div></div></div>
<div class="panel">
 <h2>Posted account balances</h2>
 <p class="muted">Calculated from posted journal lines, including receivables/payables and advances. Currencies are never combined.</p>
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
 @foreach($party->currencies as $currency)
 @php($balance=$accountBalances[$currency->id]??\Brick\Math\BigDecimal::zero())
 <div style="padding:15px;border:1px solid #dce7f2;border-radius:12px">
 <small>{{ $currency->code }}</small>
 <h2><x-finance-amount :amount="$balance->abs()" /></h2>
 <small>{{ $balance->isZero()?'Settled':($balance->isPositive()?($type==='customer'?'They owe us':'We owe them'):($type==='customer'?'We owe them':'They owe us')) }}</small>
 </div>
 @endforeach
 </div>
 <p><a class="btn btn-light" href="{{ route('finance.parties.statement',$party) }}">View full statement</a></p>
</div>
<div class="panel">
 <h2>Recent cash movements</h2>
 <div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Currency</th><th>Description</th></tr></thead><tbody>
 @forelse($recentPayments as $payment)
 <tr><td>{{ $payment->transaction_date }}</td><td>{{ ucfirst($payment->type) }}</td>
 <td><x-finance-amount :amount="$payment->amount" /></td><td>{{ $payment->currency?->code }}</td>
 <td>{{ $payment->description }}</td></tr>
 @empty<tr><td colspan="5">No cash movements posted to this account.</td></tr>
 @endforelse
 </tbody></table></div>
</div>
<div class="panel"><h2>Enabled Currencies</h2><p>{{ $party->currencies->pluck('code')->join(', ') ?: 'No currencies enabled' }}</p></div>
<div class="panel"><h2>Opening Balances</h2><div class="table-wrap"><table><thead><tr><th>Currency</th><th>Amount</th><th>Direction</th></tr></thead><tbody>@forelse($openingBalances as $balance)<tr><td>{{ $party->currencies->firstWhere('id',$balance->currency_id)?->code ?: $balance->currency_id }}</td><td><x-finance-amount :amount="\Brick\Math\BigDecimal::of((string)$balance->amount)->abs()" /></td><td>{{ \Brick\Math\BigDecimal::of((string)$balance->amount)->compareTo(\Brick\Math\BigDecimal::zero()) >= 0 ? ($type==='customer'?'They owe us':'Advance paid') : ($type==='customer'?'Customer advance':'We owe them') }}</td></tr>@empty<tr><td colspan="3">No opening balances recorded.</td></tr>@endforelse</tbody></table></div></div>
<div class="panel"><h2>Recent {{ $type==='customer'?'Receivables':'Payables' }}</h2><div class="table-wrap"><table><thead><tr><th>Document</th><th>Date</th><th>Due date</th><th>Outstanding after payments and credits</th><th>Currency</th></tr></thead><tbody>@forelse($documents as $document)<tr><td>{{ $document->number }}</td><td>{{ $document->document_date }}</td><td>{{ $document->due_date }}</td><td><x-finance-amount :amount="$document->outstanding_amount" /></td><td>{{ $party->currencies->firstWhere('id',$document->currency_id)?->code ?: $document->currency_id }}</td></tr>@empty<tr><td colspan="5">No financial documents recorded.</td></tr>@endforelse</tbody></table></div><p class="muted">Showing up to 100 recent documents.</p></div>

@canany(['administration.update','finance.update'])
<section class="panel">
 <h2>{{ $type==='customer'?'Record customer receipt':'Record supplier payment' }}</h2>
 <p class="muted">Post a cash movement to the party ledger. Select an outstanding invoice to allocate the full payment automatically; leave it unallocated if this is an advance or a general account payment.</p>
 @if($cashboxes->isEmpty())
  <p class="muted">Configure an active cashbox, bank or sarafi account in a currency enabled for this account before recording a payment.</p>
 @else
 <form method="POST" action="{{ route('finance.journals.quick.store') }}" class="party-payment-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
  @csrf
  <input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
  <input type="hidden" name="trade_party_id" value="{{ $party->id }}">
  <input type="hidden" name="return_to_party" value="1">
  <input type="hidden" name="type" value="{{ $type==='customer'?'credit':'debit' }}">
  <label>Payment date <input type="date" name="transaction_date" required value="{{ old('transaction_date',date('Y-m-d')) }}"></label>
  <label>Payment account <select name="financial_account_id" id="party-payment-cashbox" required>
   <option value="">Choose cashbox / bank / sarafi</option>
   @foreach($cashboxes as $cashbox)
   <option value="{{ $cashbox->id }}" data-currency="{{ $cashbox->currency_id }}" @selected(old('financial_account_id')==$cashbox->id)>{{ ucfirst($cashbox->type) }}: {{ $cashbox->name }} — {{ $cashbox->currency?->code }}</option>
   @endforeach
  </select></label>
  <label>Currency <select name="currency_id" id="party-payment-currency" required>
   <option value="">Select currency</option>
   @foreach($party->currencies as $currency)
   <option value="{{ $currency->id }}" @selected(old('currency_id')==$currency->id)>{{ $currency->code }}</option>
   @endforeach
  </select></label>
  <label>Amount <input type="text" name="amount" required inputmode="decimal" maxlength="25" placeholder="0.00" value="{{ old('amount') }}"></label>
  <label>Settle invoice (optional) <select name="trade_document_id" id="party-payment-invoice">
   <option value="">Unallocated payment / advance</option>
   @foreach($documents as $document)
    @if(\Brick\Math\BigDecimal::of((string)$document->outstanding_amount)->isPositive())
    <option value="{{ $document->id }}" data-currency="{{ $document->currency_id }}" @selected(old('trade_document_id')==$document->id)>{{ $document->number }} — {{ $document->outstanding_amount }} (remaining)</option>
    @endif
   @endforeach
  </select></label>
  <label style="grid-column:1/-1">Description <input name="description" maxlength="2000" value="{{ old('description') }}" placeholder="Payment reference or notes"></label>
  <div style="grid-column:1/-1"><button type="submit" class="btn">{{ $type==='customer'?'Post receipt':'Post payment' }}</button></div>
 </form>
 <script>
 document.addEventListener('DOMContentLoaded',function(){
  const currency=document.getElementById('party-payment-currency');
  const cashbox=document.getElementById('party-payment-cashbox');
  const invoice=document.getElementById('party-payment-invoice');
  const sync=()=>{
   const selectedCurrency=currency.value;
   [...cashbox.options].forEach(option=>{if(option.value)option.hidden=!!selectedCurrency && option.dataset.currency!==selectedCurrency;});
   [...invoice.options].forEach(option=>{if(option.value)option.hidden=!!selectedCurrency && option.dataset.currency!==selectedCurrency;});
   if(cashbox.selectedOptions[0]?.hidden)cashbox.value='';
   if(invoice.selectedOptions[0]?.hidden)invoice.value='';
  };
  currency.addEventListener('change',sync);
  cashbox.addEventListener('change',function(){const selected=cashbox.selectedOptions[0];if(selected?.dataset.currency){currency.value=selected.dataset.currency;sync();}});
  sync();
 });
 </script>
 @endif
</section>
@endcanany
@endsection
