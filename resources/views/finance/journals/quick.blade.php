<style>
.qt-shell{display:grid;grid-template-columns:minmax(305px,360px) minmax(0,1fr);gap:16px;align-items:start}
.qt-card{border:1px solid #e0e8f3;border-radius:13px;background:#fff;padding:19px;min-width:0}
.qt-card h2{font-size:17px;margin:0 0 5px;color:#153e6b}.qt-caption{font-size:12px;color:#788aa1;line-height:1.6;margin:0 0 15px}
.qt-form{display:grid;gap:12px}.qt-form label{display:grid;gap:5px;font-size:12px;font-weight:700;color:#2d4d70;min-width:0}
.qt-form input,.qt-form select,.qt-form textarea{display:block;width:100%;min-width:0;box-sizing:border-box;min-height:41px;border:1px solid #d5e1ee;border-radius:9px;padding:9px 11px;color:#1a3858;font:inherit;background:#fff}
.qt-form textarea{min-height:67px;resize:vertical}.qt-form input:focus,.qt-form select:focus,.qt-form textarea:focus{outline:0;border-color:#3a86cb;box-shadow:0 0 0 3px #3a86cb1a}
.qt-type{display:grid;grid-template-columns:1fr 1fr;gap:8px}.qt-type label{display:flex;gap:7px;align-items:center;justify-content:center;border:1px solid #cfddec;border-radius:10px;padding:10px;cursor:pointer;text-align:center;background:#f8fbff;color:#355677;font-size:12px;font-weight:750}
.qt-type label:has(input:checked){background:#e7f4ee;border-color:#82c8a6;color:#166544}.qt-type label.qt-debit:has(input:checked){background:#fff0eb;border-color:#edb69f;color:#a74426}
.qt-type input{position:absolute;opacity:0;width:1px;height:1px;min-height:0}.qt-type label:focus-within{outline:2px solid #79a9dd;outline-offset:2px}
.qt-amount-hint,.qt-help{font-size:11px;color:#7890a7}.qt-form .qt-submit{min-height:43px;border-radius:9px;font-weight:750;width:100%;background:#125ba0;color:#fff;border:0;cursor:pointer}.qt-form .qt-submit:hover{background:#0d477e}
.qt-mini-note{padding:10px 11px;background:#f3f8ff;border-radius:8px;font-size:11px;line-height:1.5;color:#4a6686}
.qt-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:9px;margin-bottom:12px}.qt-tabs{display:flex;gap:6px;flex-wrap:wrap}.qt-tabs a{color:#315b88;text-decoration:none;border:1px solid #d9e4f1;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700}.qt-tabs a.active{color:#fff;background:#175da1;border-color:#175da1}
.qt-table{width:100%;font-size:12px;border-collapse:collapse}.qt-table th{text-align:left;white-space:nowrap;background:#f5f8fc;color:#68809d;padding:11px 10px;font-size:11px}.qt-table td{padding:12px 10px;border-bottom:1px solid #edf1f6;vertical-align:middle}.qt-table td:last-child{max-width:220px;overflow-wrap:anywhere}.qt-pair-name{font-weight:700;color:#1f4164}.qt-money{font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}.qt-money.credit{color:#15815a}.qt-money.debit{color:#b24a35}.qt-pill{font-size:10px;border-radius:16px;padding:5px 8px;display:inline-block;background:#eaf7f0;color:#17794a;font-weight:700}.qt-pill.debit{background:#fff0e9;color:#ac4c2e}.qt-empty{padding:30px 15px;text-align:center;font-size:12px;color:#7b8ca0}
.qt-finance-select{display:grid;gap:7px}.qt-finance-select.is-needed{display:grid}
@media(max-width:1100px){.qt-shell{grid-template-columns:minmax(0,1fr)}}@media(max-width:600px){.qt-card{padding:13px}.qt-table{min-width:620px}}

.qt-cash-empty{font-size:11px;color:#9e561b;background:#fff6e9;border:1px solid #f1ddbd;padding:10px;border-radius:9px;line-height:1.5}.qt-cash-empty[hidden]{display:none!important}
.qt-cash-dialog{border:1px solid #d5e2ef;border-radius:15px;width:min(440px,calc(100vw - 26px));padding:0;box-shadow:0 22px 70px #0b2b5052;background:#fff;max-height:88vh}.qt-cash-dialog::backdrop{background:#081e3c88}.qt-cash-dialog .qt-dialog-head{display:flex;align-items:center;justify-content:space-between;padding:17px 20px;border-bottom:1px solid #e5ecf4}.qt-cash-dialog .qt-dialog-head h3{margin:0;font-size:17px;color:#194267}.qt-cash-dialog .qt-dialog-head button{border:0;background:transparent;color:#6c8197;font-size:23px;cursor:pointer}.qt-cash-dialog .qt-dialog-body{display:grid;gap:12px;padding:18px 20px}.qt-cash-dialog label{display:grid;gap:6px;font-size:12px;color:#355473;font-weight:700}.qt-cash-dialog input,.qt-cash-dialog select{min-height:41px;padding:9px 11px;border-radius:8px;border:1px solid #d5e1ef;width:100%;box-sizing:border-box}.qt-cash-dialog .qt-dialog-foot{display:flex;justify-content:end;gap:9px;padding:12px 20px 17px;border-top:1px solid #e6ecf4}.qt-cash-dialog .qt-dialog-foot button{min-height:39px;padding:8px 16px;border:1px solid #b8d0e7;border-radius:9px;cursor:pointer}.qt-dialog-save{background:#155d9f;color:white;border-color:#155d9f!important}.qt-dialog-error{font-size:12px;color:#a6322a;margin:0}.qt-dialog-error[hidden]{display:none}
</style>
<div class="qt-shell">
 <section class="qt-card">
  <h2>Quick Credit / Debit</h2>
  <p class="qt-caption">Record receipts, payments or transfers against a linked account. Balanced journal lines are created automatically.</p>
  @canany(['administration.update','finance.update'])
  <form class="qt-form" method="POST" action="{{ route('finance.journals.quick-account.store') }}" id="quick-party-transaction">
   @csrf<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
   <label>Transaction date<input type="date" name="transaction_date" required value="{{ old('transaction_date',now()->toDateString()) }}"></label>
   <label>Account / Counterpart<select name="counterpart_key" required id="qt-party">
<option value="">Select an account</option>
<optgroup label="Customers">@foreach($quickParties->where('type','customer') as $party)
<option value="party:{{ $party->id }}" data-currencies="{{ $party->currencies->pluck('id')->implode(',') }}" @selected(old('counterpart_key')==='party:'.$party->id)>{{ $party->name }}</option>
@endforeach</optgroup>
<optgroup label="Suppliers">@foreach($quickParties->where('type','supplier') as $party)
<option value="party:{{ $party->id }}" data-currencies="{{ $party->currencies->pluck('id')->implode(',') }}" @selected(old('counterpart_key')==='party:'.$party->id)>{{ $party->name }}</option>
@endforeach</optgroup>
<optgroup label="Cashbooks, Banks and Sarafis">@foreach($quickFinancials as $financial)
<option value="financial:{{ $financial->id }}" data-currencies="{{ $financial->currency_id }}">{{ $financial->name }} ({{ ucfirst($financial->type) }})</option>
@endforeach</optgroup>
<optgroup label="Other General Ledger Accounts">@foreach($quickGeneralLedgers as $ledger)
<option value="ledger:{{ $ledger->id }}" data-currencies="any">{{ $ledger->code }} — {{ $ledger->name }}</option>
@endforeach</optgroup>
</select></label>
   <fieldset style="border:0;padding:0;margin:0"><legend style="font-size:12px;font-weight:700;color:#2d4d70;margin-bottom:7px">Transaction type</legend><div class="qt-type"><label><input type="radio" name="type" value="credit" @checked(old('type','credit')==='credit')> <i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Credit · Received</label><label class="qt-debit"><input type="radio" name="type" value="debit" @checked(old('type')==='debit')> <i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Debit · Paid</label></div></fieldset>
   <div class="qt-finance-select" id="qt-cash-wrap"><label>Cashbox / Cashbook<select name="cash_account_id" id="qt-cash" required><option value="">Select cashbox</option><option value="__add_new__">+ Add New Cashbox</option></select></label><p class="qt-cash-empty" id="qt-cash-empty" hidden>No active cashbox exists. Choose “+ Add New Cashbox” to create one.</p></div>
   <label>Currency (automatic)<input type="hidden" name="currency_id" id="qt-currency" value="{{ old('currency_id') }}"><input type="text" id="qt-currency-display" value="" placeholder="Select a cashbook first" readonly aria-label="Currency selected by cashbook"></label>
   <label>Amount<input type="text" name="amount" id="qt-amount" inputmode="decimal" autocomplete="off" required placeholder="0.00" value="{{ old('amount') }}"></label>
   <label>Note / Description<textarea name="description" maxlength="2000" placeholder="Optional payment note">{{ old('description') }}</textarea></label>
   <button class="qt-submit" type="submit"><i class="fa-solid fa-check"></i> Save and post transaction</button>
  </form>
  @else<p class="qt-caption">You have read-only access to this journal.</p>@endcanany
 </section>
 <section class="qt-card">
  <div class="qt-head"><div><h2>Transaction Journal</h2><p class="qt-caption" style="margin:4px 0 0">All recorded money movements with their journal numbers.</p></div><div class="qt-tabs"><a class="{{ !request('tx_type')?'active':'' }}" href="{{ route('finance.journals.index') }}">All</a><a class="{{ request('tx_type')==='receipt'?'active':'' }}" href="{{ route('finance.journals.index',['tx_type'=>'receipt']) }}">Credits</a><a class="{{ request('tx_type')==='payment'?'active':'' }}" href="{{ route('finance.journals.index',['tx_type'=>'payment']) }}">Debits</a><a class="{{ request('tx_type')==='transfer'?'active':'' }}" href="{{ route('finance.journals.index',['tx_type'=>'transfer']) }}">Transfers</a></div></div>
  <div class="table-wrap"><table class="qt-table"><thead><tr><th>Date</th><th>Journal</th><th>Party / Account</th><th>Type</th><th>Currency</th><th>Amount</th><th>Note</th></tr></thead><tbody>
   @forelse($transactions as $transaction)
    @php($in=$transaction->type==='receipt')
    <tr><td>{{ \Illuminate\Support\Carbon::parse($transaction->transaction_date)->format('d M Y') }}</td><td><strong>{{ $transaction->journal?->number ?: '—' }}</strong></td><td><span class="qt-pair-name">@if($transaction->type==='transfer'){{ $transaction->source?->name ?? '—' }} → {{ $transaction->destination?->name ?? '—' }}@else{{ $transactionPartyNames[$transaction->counterpart_ledger_account_id] ?? $transactionLedgerNames[$transaction->counterpart_ledger_account_id] ?? ($transaction->source?->name ?? $transaction->destination?->name ?? 'Other ledger') }}@endif</span></td><td><span class="qt-pill {{ $in?'':'debit' }}">{{ $transaction->type==='transfer'?'Transfer':($in?'Credit':'Debit') }}</span></td><td>{{ $transaction->currency?->code }}</td><td><span class="qt-money {{ $transaction->type==='transfer'?'':($in?'credit':'debit') }}">{{ \Brick\Math\BigDecimal::of((string)$transaction->amount)->toScale(2,\Brick\Math\RoundingMode::HalfUp) }}</span></td><td>{{ $transaction->description }}</td></tr>
   @empty<tr><td colspan="7" class="qt-empty">No transactions found. Record a credit or debit to start the journal.</td></tr>@endforelse
  </tbody></table></div>
  {{ $transactions->onEachSide(1)->links('vendor.pagination.kanozay') }}
 </section>
</div>
@canany(['administration.update','finance.update'])
<dialog id="qt-new-cashbox" class="qt-cash-dialog" aria-labelledby="qt-cashbox-title">
<form id="qt-cashbox-form" method="POST" action="{{ route('finance.accounts.store') }}">
@csrf<input type="hidden" name="type" value="cashbox">
<div class="qt-dialog-head"><h3 id="qt-cashbox-title">Add Cashbox</h3><button type="button" id="qt-cashbox-close" aria-label="Close">×</button></div>
<div class="qt-dialog-body"><p class="qt-caption" style="margin:0">Create a cashbox for payments. This creates an account and its ledger mapping only, without an opening balance.</p>
<label>Cashbox Name<input name="name" placeholder="e.g. Main Cash — Kabul" maxlength="160" required></label>
<label>Currency<select name="currency_id" id="qt-cashbox-currency" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
<p id="qt-cashbox-error" class="qt-dialog-error" role="alert" hidden></p>
</div>
<div class="qt-dialog-foot"><button type="button" id="qt-cashbox-cancel">Cancel</button><button type="submit" class="qt-dialog-save">Save Cashbox</button></div>
</form>
</dialog>
@endcanany
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('quick-party-transaction');if(!form)return;
 const cashboxes=@json($quickCashboxes);
 const currencies=@json($currencies);
 const select=form.querySelector('#qt-cash');
 const currency=form.querySelector('#qt-currency'),display=form.querySelector('#qt-currency-display');
 const party=form.querySelector('#qt-party'),empty=document.getElementById('qt-cash-empty');
 const refreshSelect2=element=>{
  if(window.jQuery&&window.jQuery(element).data('select2'))window.jQuery(element).trigger('change.select2');
 };
 const syncCurrency=()=>{
  const cash=cashboxes.find(a=>String(a.id)===String(select.value));
  const selectedCurrency=cash?currencies.find(c=>String(c.id)===String(cash.currency_id)):null;
  currency.value=selectedCurrency?String(selectedCurrency.id):'';
  display.value=selectedCurrency?selectedCurrency.code+' — '+selectedCurrency.name:'';
  [...party.options].forEach(option=>{
   if(!option.value)return;
   const supported=option.dataset.currencies||'';
   const allowed=!!selectedCurrency&&(supported==='any'||supported.split(',').includes(String(selectedCurrency.id)));
   // Disallow using a cashbook as its own financial counterpart.
   const sameCashbook=option.value==='financial:'+select.value;
   option.disabled=!allowed||sameCashbook;
   if(option.disabled&&party.value===option.value)party.value='';
  });
  refreshSelect2(party);
 };
 const renderCashbooks=(preferred='')=>{
  select.replaceChildren(new Option('Select cashbox',''),new Option('+ Add New Cashbox','__add_new__'));
  cashboxes.forEach(a=>{
   const currencyInfo=currencies.find(c=>String(c.id)===String(a.currency_id));
   select.add(new Option(a.name+(currencyInfo?' ('+currencyInfo.code+')':''),String(a.id)));
  });
  const valid=cashboxes.some(a=>String(a.id)===String(preferred));
  select.value=valid?String(preferred):(cashboxes.length===1?String(cashboxes[0].id):'');
  empty.hidden=cashboxes.length!==0;
  refreshSelect2(select);
  syncCurrency();
 };
 renderCashbooks(@json(old('cash_account_id','')));
 const directoryPartyId=@json(request('trade_party_id'));
 if(directoryPartyId && !party.value){
  const match=[...party.options].find(option=>option.value==='party:'+directoryPartyId && !option.disabled);
  if(match){party.value=match.value;refreshSelect2(party);}
 }
 const modal=document.getElementById('qt-new-cashbox');
 if(modal){
  const createForm=document.getElementById('qt-cashbox-form'),error=document.getElementById('qt-cashbox-error');
  let lastCashboxId=select.value;
  const openCashboxModal=()=>{
   select.value=lastCashboxId&&lastCashboxId!=='__add_new__'?lastCashboxId:'';
   refreshSelect2(select);syncCurrency();
   createForm.reset();error.hidden=true;
   const modalCurrency=document.getElementById('qt-cashbox-currency');
   const base=currencies.find(c=>c.is_base);
   modalCurrency.value=currency.value||(base?String(base.id):'');
   refreshSelect2(modalCurrency);
   if(!modal.open)modal.showModal();
   createForm.querySelector('input[name=name]').focus();
  };
  const onCashboxChange=()=>{
   if(select.value==='__add_new__'){openCashboxModal();return;}
   lastCashboxId=select.value;syncCurrency();
  };
  select.addEventListener('change',onCashboxChange);
  if(window.jQuery){
   window.jQuery(select).on('select2:select.qtCashbox',event=>{
    if(String(event.params?.data?.id)==='__add_new__')openCashboxModal();
   }).on('change.qtCashbox',onCashboxChange);
  }
  ['qt-cashbox-close','qt-cashbox-cancel'].forEach(id=>document.getElementById(id)?.addEventListener('click',()=>modal.close()));
  createForm.addEventListener('submit',async event=>{
   event.preventDefault();const save=createForm.querySelector('button[type=submit]');save.disabled=true;error.hidden=true;
   try{
    const response=await fetch(createForm.action,{method:'POST',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:new FormData(createForm)});
    const data=await response.json();
    if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'Could not create cashbox.');
    const created=data.account;
    cashboxes.push({id:created.id,name:created.name,currency_id:created.currency_id});
    renderCashbooks(created.id);lastCashboxId=select.value;modal.close();
    if(window.Swal)window.Swal.fire({icon:'success',title:'Cashbox added',text:created.name,showConfirmButton:false,timer:1700});
   }catch(e){error.textContent=e.message;error.hidden=false;}finally{save.disabled=false;}
  });
 }else{
  select.addEventListener('change',syncCurrency);
  if(window.jQuery)window.jQuery(select).on('change.qtCashbox',syncCurrency);
 }
 const amount=form.querySelector('#qt-amount');
 amount.addEventListener('input',()=>{
  const cleaned=amount.value.replace(/[^0-9.,]/g,'').replace(/,/g,'');
  const parts=cleaned.split('.');
  let value=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,',');
  if(parts.length>1)value+='.'+parts.slice(1).join('').slice(0,4);
  amount.value=value;
 });
 form.addEventListener('submit',event=>{
  const validCash=cashboxes.some(c=>String(c.id)===String(select.value));
  if(!validCash||!currency.value){
   event.preventDefault();
   if(window.Swal)window.Swal.fire({icon:'warning',title:'Select a cashbook',text:'Choose an existing cashbook or add a new one before posting.'});
   else alert('Choose a cashbook before posting.');
   return;
  }
  form.querySelector('button[type=submit]').disabled=true;
 });
});
</script>
