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
.qt-finance-select{display:none}.qt-finance-select.is-needed{display:grid}
@media(max-width:1100px){.qt-shell{grid-template-columns:minmax(0,1fr)}}@media(max-width:600px){.qt-card{padding:13px}.qt-table{min-width:620px}}
</style>
<div class="qt-shell">
 <section class="qt-card">
  <h2>Quick Credit / Debit</h2>
  <p class="qt-caption">Record money received from a customer or supplier, or paid to them. Each entry automatically creates a balanced posted journal.</p>
  @canany(['administration.update','finance.update'])
  <form class="qt-form" method="POST" action="{{ route('finance.journals.quick.store') }}" id="quick-party-transaction">
   @csrf<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
   <label>Transaction date<input type="date" name="transaction_date" required value="{{ old('transaction_date',now()->toDateString()) }}"></label>
   <label>Customer / Supplier<select name="trade_party_id" required id="qt-party"><option value="">Select customer or supplier</option>@foreach($quickParties as $party)<option value="{{ $party->id }}" data-currencies="{{ $party->currencies->pluck('id')->implode(',') }}" @selected((string)old('trade_party_id')===(string)$party->id)>{{ $party->name }} ({{ ucfirst($party->type) }})</option>@endforeach</select></label>
   <fieldset style="border:0;padding:0;margin:0"><legend style="font-size:12px;font-weight:700;color:#2d4d70;margin-bottom:7px">Transaction type</legend><div class="qt-type"><label><input type="radio" name="type" value="credit" @checked(old('type','credit')==='credit')> <i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Credit · Received</label><label class="qt-debit"><input type="radio" name="type" value="debit" @checked(old('type')==='debit')> <i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Debit · Paid</label></div></fieldset>
   <label>Currency<select name="currency_id" id="qt-currency" required><option value="">Select currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((string)old('currency_id')===(string)$currency->id || (!old('currency_id') && $currency->is_base))>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
   <label class="qt-finance-select" id="qt-cash-wrap">Cashbox<select name="cash_account_id" id="qt-cash"><option value="">Select cashbox</option></select><span class="qt-help">Required when multiple cashboxes use this currency</span></label>
   <label>Amount<input type="text" name="amount" id="qt-amount" inputmode="decimal" autocomplete="off" required placeholder="0.00" value="{{ old('amount') }}"><span class="qt-amount-hint">Use a positive amount; commas are allowed.</span></label>
   <label>Note / Description<textarea name="description" maxlength="2000" placeholder="Optional payment note">{{ old('description') }}</textarea></label>
   <div class="qt-mini-note" id="qt-direction-info">Credit means money received into the selected cashbox. Debit means money paid out.</div>
   <button class="qt-submit" type="submit"><i class="fa-solid fa-check"></i> Save and post transaction</button>
  </form>
  @else<p class="qt-caption">You have read-only access to this journal.</p>@endcanany
 </section>
 <section class="qt-card">
  <div class="qt-head"><div><h2>Transaction Journal</h2><p class="qt-caption" style="margin:4px 0 0">All recorded money movements with their journal numbers.</p></div><div class="qt-tabs"><a class="{{ !request('tx_type')?'active':'' }}" href="{{ route('finance.journals.index') }}">All</a><a class="{{ request('tx_type')==='receipt'?'active':'' }}" href="{{ route('finance.journals.index',['tx_type'=>'receipt']) }}">Credits</a><a class="{{ request('tx_type')==='payment'?'active':'' }}" href="{{ route('finance.journals.index',['tx_type'=>'payment']) }}">Debits</a></div></div>
  <div class="table-wrap"><table class="qt-table"><thead><tr><th>Date</th><th>Journal</th><th>Party / Account</th><th>Type</th><th>Currency</th><th>Amount</th><th>Note</th></tr></thead><tbody>
   @forelse($transactions as $transaction)
    @php($in=$transaction->type==='receipt')
    <tr><td>{{ \Illuminate\Support\Carbon::parse($transaction->transaction_date)->format('d M Y') }}</td><td><strong>{{ $transaction->journal?->number ?: '—' }}</strong></td><td><span class="qt-pair-name">{{ $transactionPartyNames[$transaction->counterpart_ledger_account_id] ?? ($transaction->source?->name ?? $transaction->destination?->name ?? 'Other ledger') }}</span></td><td><span class="qt-pill {{ $in?'':'debit' }}">{{ $transaction->type==='transfer'?'Transfer':($in?'Credit':'Debit') }}</span></td><td>{{ $transaction->currency?->code }}</td><td><span class="qt-money {{ $transaction->type==='transfer'?'':($in?'credit':'debit') }}">{{ \Brick\Math\BigDecimal::of((string)$transaction->amount)->toScale(2,\Brick\Math\RoundingMode::HalfUp) }}</span></td><td>{{ $transaction->description }}</td></tr>
   @empty<tr><td colspan="7" class="qt-empty">No transactions found. Record a credit or debit to start the journal.</td></tr>@endforelse
  </tbody></table></div>
  {{ $transactions->onEachSide(1)->links('vendor.pagination.kanozay') }}
 </section>
</div>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('quick-party-transaction');if(!form)return;
 const cashboxes=@json($quickCashboxes->map(fn($item)=>['id'=>$item->id,'name'=>$item->name,'currency_id'=>$item->currency_id])->values());
 const currency=form.querySelector('#qt-currency'),select=form.querySelector('#qt-cash'),wrap=form.querySelector('#qt-cash-wrap');
 const party=form.querySelector('#qt-party');
 const sync=()=>{[...party.options].forEach(option=>{if(!option.value)return;const allowed=(option.dataset.currencies||'').split(',').includes(currency.value);option.disabled=!allowed;if(!allowed&&party.value===option.value)party.value='';});
 const filtered=cashboxes.filter(c=>String(c.currency_id)===currency.value);select.replaceChildren(new Option('Select cashbox',''));filtered.forEach(c=>select.add(new Option(c.name,c.id)));wrap.classList.toggle('is-needed',filtered.length!==1);select.required=filtered.length>1;if(filtered.length===1)select.value=String(filtered[0].id);};
 currency.addEventListener('change',sync);sync();
 const amount=form.querySelector('#qt-amount');amount.addEventListener('input',()=>{const cleaned=amount.value.replace(/[^0-9.,]/g,'').replace(/,/g,'');const parts=cleaned.split('.');let value=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,',');if(parts.length>1)value+='.'+parts.slice(1).join('').slice(0,4);amount.value=value;});
 form.addEventListener('submit',event=>{if(!cashboxes.some(c=>String(c.currency_id)===currency.value)){event.preventDefault();if(window.Swal)window.Swal.fire({icon:'warning',title:'Cashbox required',text:'Create an active cashbox for this currency in Financial Accounts.'});else alert('Create an active cashbox for this currency.');return;}form.querySelector('button[type=submit]').disabled=true;});
});
</script>
