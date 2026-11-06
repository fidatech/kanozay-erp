@extends('layouts.kanozay')
@section('content')
<style>
.sp-wrap{max-width:100%;}.sp-register{padding:19px!important}
.sp-filter{display:flex;gap:9px;align-items:center;margin:15px 0}.sp-filter input{flex:1;min-width:150px;min-height:40px;border:1px solid #dce5f0;border-radius:9px;padding:9px 12px}.sp-filter .btn{white-space:nowrap}
.sp-table{width:100%;border-collapse:collapse;font-size:12px}.sp-table th{padding:12px 10px;background:#f2f6fb;text-align:left;color:#526d8a;font-size:11px;white-space:nowrap}.sp-table td{padding:13px 10px;border-bottom:1px solid #e8edf4;vertical-align:middle}.sp-table td strong{color:#1a4169}.sp-table td small{display:block;color:#7890a6;margin-top:3px}
.sp-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.sp-action{border:1px solid #d6e3f1;background:#fff;color:#235a8a;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}.sp-action:hover{background:#eaf4ff}.sp-action.danger{border-color:#f0cbcb;color:#ad3232}.sp-action.whatsapp{color:#19834b;border-color:#b9e6cb}.sp-action[disabled]{opacity:.45;cursor:not-allowed}
.sp-actions form{margin:0}.sp-balance{display:block;white-space:nowrap;font-size:11px;margin:3px 0;font-variant-numeric:tabular-nums}.sp-balance small{display:inline;color:#6f8194}.sp-muted{color:#8a9bae;font-size:11px}
.sp-create-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.sp-create-form label,.sp-edit-form label{display:grid;gap:6px;color:#355473;font-size:12px;font-weight:700}.sp-create-form input,.sp-create-form select,.sp-edit-form input{min-height:42px;width:100%;box-sizing:border-box;border:1px solid #d5e1ed;border-radius:8px;padding:9px 11px}.sp-create-form button{justify-self:end;grid-column:1/-1}.sp-create-form .sp-wide{grid-column:1/-1}
.sp-dialog{width:min(520px,calc(100vw - 24px));max-height:87vh;border:1px solid #d9e5f2;border-radius:14px;padding:0;box-shadow:0 22px 70px #143c6555}.sp-dialog::backdrop{background:rgba(10,27,48,.55)}.sp-dialog-head{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e5ebf3}.sp-dialog-head h2{margin:0;font-size:18px}.sp-dialog-head button{border:none;background:transparent;font-size:22px;cursor:pointer;color:#6b7f93}.sp-edit-form{padding:18px 20px;display:grid;grid-template-columns:1fr 1fr;gap:12px}.sp-dialog-footer{grid-column:1/-1;display:flex;gap:9px;justify-content:flex-end;margin-top:7px}.sp-dialog-footer button{min-height:38px}
@media(max-width:720px){.sp-create-form,.sp-edit-form{grid-template-columns:1fr}.sp-create-form .sp-wide,.sp-create-form button{grid-column:1}.sp-table{min-width:820px}.sp-filter{flex-wrap:wrap}}
</style>
<div class="heading">
 <div><h1><i class="fa-solid {{ $type==='customer'?'fa-users':'fa-truck-field' }}"></i> {{ ucfirst($type) }}s</h1><p>Manage {{ $type }} details and view account balances.</p></div>
</div>
@if($errors->any())<div class="error" data-form-errors role="alert">{{ $errors->first() }}</div>@endif
<div class="sp-wrap">
<div class="party-list">
<section class="panel">
 <h2>Add {{ ucfirst($type) }}</h2>
 @canany(['administration.update','finance.update'])
 <form class="sp-create-form" data-new-entry-form="1" method="POST" action="{{ route('finance.parties.store') }}">
  @csrf<input type="hidden" name="type" value="{{ $type }}">
  <label>Name<input name="name" maxlength="180" required value="{{ old('name') }}"></label>
  <label>Phone<input name="phone" maxlength="80" value="{{ old('phone') }}" placeholder="Phone or WhatsApp number"></label>
  <label>Address<input name="address" maxlength="2000" value="{{ old('address') }}"></label>
  <label>Email (optional)<input type="email" name="email" maxlength="180" value="{{ old('email') }}"></label>
  <label>Code (optional)<input name="code" maxlength="40" value="{{ old('code') }}"></label>
  <label>Currencies<select name="currency_ids[]" multiple required>@foreach($currencies as $currency)
   <option value="{{ $currency->id }}" @selected(in_array($currency->id,old('currency_ids',[])))>{{ $currency->code }}</option>
  @endforeach</select></label>
  <button class="btn" type="submit">Create {{ ucfirst($type) }}</button>
 </form>
 @endcanany
</section>
<section class="panel sp-register">
 <h2>{{ ucfirst($type) }} Register</h2>
 <form class="sp-filter" method="GET" action="{{ $type==='customer'?route('finance.customers.index'):route('finance.suppliers.index') }}">
  <input type="search" name="search" maxlength="180" value="{{ request('search') }}" placeholder="Search by name, code, phone or email" aria-label="Search {{ $type }}s">
  <button type="submit" class="btn">Search</button>
  @if(request()->filled('search'))<a class="sp-action" href="{{ $type==='customer'?route('finance.customers.index'):route('finance.suppliers.index') }}">Clear</a>@endif
 </form>
 <div class="table-wrap"><table class="sp-table">
 <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Address</th><th>Currencies</th><th>Posted Balance</th><th>Actions</th></tr></thead>
 <tbody>
 @forelse($parties as $party)
  @php
    $lines=[];
    foreach($party->currencies as $cur){
      $balance=$partyBalances[$party->id][$cur->id]??\Brick\Math\BigDecimal::zero();
      $direction=$balance->isPositive()?($type==='customer'?'They owe us':'We owe them'):($balance->isNegative()?($type==='customer'?'We owe them':'They owe us'):'Settled');
      $lines[]=$cur->code.' '.(string)$balance->abs()->toScale(2,\Brick\Math\RoundingMode::HalfUp).' — '.$direction;
    }
    $message="Hello ".$party->name.",\nYour current ".$type." account balance:\n".implode("\n",$lines)."\nPlease contact our accounts department if you have any questions.";
  @endphp
  <tr>
   <td>{{ $party->code ?: '—' }}</td>
   <td><a href="{{ route($type==='customer'?'finance.customers.show':'finance.suppliers.show',$party) }}"><strong>{{ $party->name }}</strong></a><small>{{ $party->email }}</small></td>
   <td>{{ $party->phone ?: '—' }}</td><td>{{ $party->address ?: '—' }}</td>
   <td>{{ $party->currencies->pluck('code')->join(', ') }}</td>
   <td>@foreach($lines as $line)<span class="sp-balance">{{ $line }}</span>@endforeach</td>
   <td><div class="sp-actions">
    @canany(['administration.update','finance.update'])
     <button class="sp-action" type="button" data-edit-party="{{ $party->id }}"><i class="fa-solid fa-pen"></i> Edit</button>
     <form method="POST" class="sp-delete-form" action="{{ route('finance.parties.destroy',$party) }}" data-name="{{ $party->name }}">@csrf @method('DELETE')
      <button class="sp-action danger" type="submit"><i class="fa-solid fa-trash"></i> Delete</button>
     </form>
    @endcanany
    <a class="sp-action" href="{{ route('finance.parties.statement',$party) }}"><i class="fa-solid fa-file-lines"></i> View Statement</a>
    <button type="button" class="sp-action whatsapp" data-whatsapp-phone="{{ $party->phone }}" data-whatsapp-message="{{ $message }}" @if(!$party->phone) disabled title="Add a phone number first" @endif><i class="fa-brands fa-whatsapp"></i> WhatsApp</button>
   </div></td>
  </tr>
 @empty
 <tr><td colspan="7" style="padding:28px;text-align:center;color:#8394a9">No {{ $type }}s found.</td></tr>
 @endforelse
 </tbody></table></div>
 {{ $parties->links() }}
</section>
</div>
</div>
@canany(['administration.update','finance.update'])
@foreach($parties as $party)
<dialog class="sp-dialog" id="sp-edit-{{ $party->id }}">
 <div class="sp-dialog-head"><h2>Edit {{ ucfirst($type) }} — {{ $party->name }}</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
 <form class="sp-edit-form" method="POST" action="{{ route($type==='customer'?'finance.customers.update':'finance.suppliers.update',$party) }}">
  @csrf @method('PATCH')
  <label>Name<input name="name" required maxlength="180" value="{{ $party->name }}"></label>
  <label>Phone<input name="phone" maxlength="80" value="{{ $party->phone }}"></label>
  <label>Address<input name="address" maxlength="2000" value="{{ $party->address }}"></label>
  <label>Email<input type="email" name="email" maxlength="180" value="{{ $party->email }}"></label>
  <label>Code<input name="code" maxlength="40" value="{{ $party->code }}"></label>
  <div class="sp-dialog-footer"><button type="button" class="sp-action" data-close-dialog>Cancel</button><button class="btn" type="submit">Save Changes</button></div>
 </form>
</dialog>
@endforeach
@endcanany
<script>
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-edit-party]').forEach(button=>button.addEventListener('click',()=>document.getElementById('sp-edit-'+button.dataset.editParty)?.showModal()));
 document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
 document.querySelectorAll('.sp-delete-form').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();
  const name=form.dataset.name;
  const confirmed=window.Swal
   ? (await window.Swal.fire({title:'Delete '+name+'?',text:'Only unused accounts without transactions can be deleted.',icon:'warning',showCancelButton:true,confirmButtonText:'Delete',confirmButtonColor:'#be3434',focusCancel:true})).isConfirmed
   : window.confirm('Delete unused account '+name+'?');
  if(confirmed)HTMLFormElement.prototype.submit.call(form);
 }));
 document.querySelectorAll('[data-whatsapp-phone]').forEach(button=>button.addEventListener('click',()=>{
  let number=(button.dataset.whatsappPhone||'').replace(/[^0-9]/g,'');
  if(!number){return;}
  if(number.startsWith('00'))number=number.slice(2);
  // Afghanistan mobile numbers usually start with 0; convert to WhatsApp international format.
  else if(/^0[0-9]{9}$/.test(number))number='93'+number.slice(1);
  const message=button.dataset.whatsappMessage||'';
  window.open('https://wa.me/'+number+'?text='+encodeURIComponent(message),'_blank','noopener,noreferrer');
 }));
});
</script>
@endsection
