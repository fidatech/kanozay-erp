@extends('layouts.kanozay')
@section('content')
<style>.fx-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.fx-head h1{margin:0 0 7px}.fx-head p{color:#7082a1;margin:0}.rate-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:18px}.rate-form{display:grid!important;gap:14px!important}.rate-form label{display:grid;gap:7px;font-size:12px;font-weight:700;color:#2c456d}.rate-form input,.rate-form select,.rate-form textarea{width:100%;min-height:42px;border:1px solid #d6e3f1;border-radius:7px;padding:10px 12px;font-size:13px}.rate-form textarea{min-height:75px}.rate-note{color:#71819d;line-height:1.6;font-size:12px}.rate-value{font-weight:700;color:#1b65b5;font-variant-numeric:tabular-nums}.rate-pair{display:flex;align-items:center;gap:8px}.rate-pair i{color:#7b92ae}.rate-date{white-space:nowrap}@media(max-width:1000px){.rate-grid{grid-template-columns:1fr}}</style>
<div class="fx-head"><div><h1><i class="fa-solid fa-arrow-right-arrow-left" style="color:#167f9f"></i> Exchange Rates</h1><p>Record dated conversion rates with an auditable history.</p></div><a class="btn" href="{{ route('admin.currencies.index') }}"><i class="fa-solid fa-coins"></i> Currencies</a></div>
@if($errors->any())<section class="panel" style="color:#b42318">{{ $errors->first() }}</section>@endif
<section class="panel" style="margin-bottom:18px"><h2>Historical Conversion Check</h2><p class="rate-note">Preview using the most recent recorded rate on or before the selected date. No reverse rate is assumed and no accounting entry is created.</p>
<form id="rate-converter" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;align-items:end">
<label>From<select name="from_currency_id" data-no-select2="1" data-quick-registered="1" required><option value="">Choose</option>@foreach($currencies as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach</select></label>
<label>To<select name="to_currency_id" data-no-select2="1" data-quick-registered="1" required><option value="">Choose</option>@foreach($currencies as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach</select></label>
<label>Amount<input name="amount" type="number" min="0.000001" step="any" required value="1"></label>
<label>As of<input name="as_of" type="date" required value="{{ now()->toDateString() }}"></label>
<button class="btn" type="submit">Calculate</button>
</form><p id="rate-conversion-result" class="rate-note" role="status" aria-live="polite"></p>
</section>
<style>#rate-converter label{display:grid;gap:7px;font-weight:700;font-size:12px}#rate-converter input,#rate-converter select{min-height:42px;width:100%;padding:8px;border:1px solid #d6e3f1;border-radius:8px}@media(max-width:850px){#rate-converter{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:480px){#rate-converter{grid-template-columns:1fr!important}}</style>
<script>document.getElementById('rate-converter').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget;const display=document.getElementById('rate-conversion-result');display.textContent='Calculating...';try{const query=new URLSearchParams(new FormData(form));const response=await fetch(@json(route('admin.exchange-rates.convert'))+'?'+query,{headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok){display.textContent=data.message||'Unable to calculate. Check inputs.';return;}display.textContent=data.converted_amount+' '+data.target_code+' (rate '+data.rate+', effective '+data.effective_date+', source '+(data.source||'Manual')+')';}catch(error){display.textContent='Unable to contact the server.';}});</script>
<section class="panel fx-pairs" style="margin-bottom:18px"><div class="fx-pair-title"><div><h2 style="margin-bottom:6px">Currency Pair Rate Board</h2><p class="rate-note">Every direction has its own independently recorded rate. Missing rates are shown as Not set.</p></div><label class="fx-pair-search">Find currency<input type="search" id="fx-pair-filter" placeholder="Search USD, AFN, PKR…"></label></div>
<div class="fx-pair-board">
@foreach($currencies as $source)
@foreach($currencies as $destination)
@if($source->id !== $destination->id)
@php($pairRate=$latestRates->get($source->id.':'.$destination->id))
<div class="fx-pair-card" data-pair="{{ strtolower($source->code.' '.$destination->code) }}">
<div class="fx-pair-codes"><strong>{{ $source->code }}</strong><i class="fa-solid fa-arrow-right"></i><strong>{{ $destination->code }}</strong></div>
@if($pairRate)
<div class="fx-pair-rate">{{ rtrim(rtrim((string)$pairRate->rate,'0'),'.') }}</div>
<div class="rate-note">1 {{ $source->code }} = rate in {{ $destination->code }}</div>
<small class="muted">{{ $pairRate->effective_date->format('d M Y') }} · {{ $pairRate->source ?: 'Manual' }}</small>
@else
<div class="fx-pair-missing">Not set</div><small class="muted">No rate recorded for this direction</small>
@endif
@can('administration.update')<button type="button" class="fx-pair-set" data-from="{{ $source->id }}" data-to="{{ $destination->id }}">{{ $pairRate ? 'Record new rate' : 'Set rate' }}</button>@endcan
</div>
@endif
@endforeach
@endforeach
</div></section>
<style>.fx-pair-title{display:flex;justify-content:space-between;align-items:center;gap:16px}.fx-pair-search{display:grid;gap:7px;min-width:180px;font-size:12px;font-weight:700}.fx-pair-search input{width:100%;padding:10px;border:1px solid #d6e3f1;border-radius:8px}.fx-pair-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.fx-pair-card{border:1px solid #e1eaf4;border-radius:12px;padding:17px;background:#fbfdff;display:flex;flex-direction:column;gap:8px;min-height:185px}.fx-pair-codes{display:flex;align-items:center;gap:10px;font-size:14px}.fx-pair-codes i{font-size:11px;color:#6f89aa}.fx-pair-rate{font-size:23px;font-weight:800;color:#1d649b;font-variant-numeric:tabular-nums}.fx-pair-missing{font-size:16px;color:#a16b27;font-weight:700}.fx-pair-set{margin-top:auto;border:1px solid #bad4ed;background:#edf6ff;color:#175a9a;border-radius:8px;padding:9px 12px;cursor:pointer;text-align:center;font-weight:700}.fx-pair-set:hover{background:#dbeeff}@media(max-width:650px){.fx-pair-title{display:block}.fx-pair-search{margin:12px 0}}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{document.getElementById('fx-pair-filter')?.addEventListener('input',event=>{const q=event.target.value.trim().toLowerCase();document.querySelectorAll('.fx-pair-card').forEach(card=>card.hidden=!!q&&!card.dataset.pair.includes(q));});document.querySelectorAll('.fx-pair-set').forEach(button=>button.addEventListener('click',()=>{const from=document.querySelector('.rate-form select[name="from_currency_id"]'),to=document.querySelector('.rate-form select[name="to_currency_id"]');from.value=button.dataset.from;to.value=button.dataset.to;const dialog=from.closest('dialog');if(dialog&&!dialog.open)dialog.showModal();from.focus();}));});</script>
<div class="rate-grid"><section class="panel"><h2>Record Exchange Rate</h2><p class="rate-note">Enter the number of target currency units for 1 source currency unit. Rates are entered manually and never fetched automatically.</p>@can('administration.update')<form class="rate-form" data-new-entry-form="1" method="POST" action="{{ route('admin.exchange-rates.store') }}">@csrf<label>From Currency<select name="from_currency_id" data-no-select2="1" data-quick-registered="1" required><option value="">Choose currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('from_currency_id')==$currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label><label>To Currency<select name="to_currency_id" data-no-select2="1" data-quick-registered="1" required><option value="">Choose currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('to_currency_id')==$currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label><label>Conversion Rate<input name="rate" type="number" min="0.000000000001" step="any" required placeholder="1 source = ? target" value="{{ old('rate') }}"></label><label>Effective Date<input name="effective_date" type="date" required value="{{ old('effective_date',now()->format('Y-m-d')) }}"></label><label>Rate Source<input name="source" maxlength="150" placeholder="Bank / Sarafi / Agreed rate" value="{{ old('source') }}"></label><label>Notes<textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></label><button class="btn" type="submit" @disabled($currencies->count()<2)><i class="fa-solid fa-plus"></i> Record Rate</button></form>@endcan @if($currencies->count()<2)<p class="rate-note">Add at least two active currencies before recording a rate.</p>@endif</section><section class="panel"><h2>Exchange Rate History</h2><p class="rate-note">Each new rate is retained as a separate historical record; existing rates are not overwritten.</p><div class="table-wrap"><table><thead><tr><th>Currency Pair</th><th>Rate</th><th>Effective Date</th><th>Source</th><th>Recorded By</th></tr></thead><tbody>@forelse($rates as $rate)<tr><td><div class="rate-pair"><strong>{{ $rate->fromCurrency?->code }}</strong><i class="fa-solid fa-arrow-right"></i><strong>{{ $rate->toCurrency?->code }}</strong></div><small class="muted">{{ $rate->notes }}</small></td><td class="rate-value">{{ rtrim(rtrim(number_format((float)$rate->rate,12,'.',''), '0'), '.') }}</td><td class="rate-date">{{ $rate->effective_date->format('d M Y') }}</td><td>{{ $rate->source ?: 'Manual' }}</td><td>{{ $rate->creator?->name ?? '—' }}<br><small class="muted">{{ $rate->created_at->timezone('Asia/Kabul')->format('d M Y H:i') }}</small></td></tr>@empty<tr><td colspan="5" class="empty"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i></div><strong>No exchange rates yet</strong><p>Record a currency conversion rate to begin your rate history.</p></div></td></tr>@endforelse
</tbody></table></div><div style="margin-top:16px">{{ $rates->onEachSide(1)->links('vendor.pagination.kanozay') }}</div></section></div>

@can('administration.update')
<dialog id="fx-new-currency" class="k-modal" aria-labelledby="fx-new-currency-title">
<form id="fx-new-currency-form" class="modal-form"><div class="modal-head"><h2 id="fx-new-currency-title">Add Currency</h2><button type="button" class="modal-close" id="fx-currency-close" aria-label="Close">×</button></div>
<div class="modal-body"><label>ISO Currency Code<input name="code" required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" placeholder="e.g. PKR"></label><label>Currency Name<input name="name" required maxlength="100" placeholder="e.g. Pakistani Rupee"></label><label>Symbol<input name="symbol" maxlength="12" placeholder="e.g. Rs"></label></div>
<p id="fx-currency-error" class="error" style="padding:0 20px" role="alert" hidden></p>
<div class="modal-foot"><button type="button" class="btn btn-light" id="fx-currency-cancel">Cancel</button><button class="btn" type="submit">Save and Select</button></div></form></dialog>
@endcan
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const modal=document.getElementById('fx-new-currency');
 const selects=[...document.querySelectorAll('#rate-converter select[name$="currency_id"], .rate-form select[name$="currency_id"]')];
 if(!modal)return;
 let target=null;const addValue='__fx_add_currency__';
 const openModal=select=>{target=select;modal.querySelector('form').reset();document.getElementById('fx-currency-error').hidden=true;modal.showModal();};
 selects.forEach(select=>{
  const opt=new Option('+ Add New Currency…',addValue);select.add(opt);
  select.addEventListener('change',()=>{if(select.value===addValue){select.value='';openModal(select);}});
 });
 ['fx-currency-close','fx-currency-cancel'].forEach(id=>document.getElementById(id).addEventListener('click',()=>modal.close()));
 modal.querySelector('form').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget,submit=form.querySelector('[type=submit]'),error=document.getElementById('fx-currency-error');submit.disabled=true;error.hidden=true;
  try{const data=new FormData(form);data.append('_token',@json(csrf_token()));const response=await fetch(@json(route('purchases.quick-currency')),{method:'POST',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:data});const result=await response.json();if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Could not create currency');
  selects.forEach(select=>{if(![...select.options].some(o=>o.value===String(result.id))){const option=new Option(result.text,result.id);select.add(option,select.querySelector('option[value="'+addValue+'"]'));}});
  if(target){target.value=String(result.id);target.dispatchEvent(new Event('change',{bubbles:true}));}modal.close();if(window.Swal)window.Swal.fire({icon:'success',title:'Currency created',text:result.text,timer:2000,showConfirmButton:false});
  }catch(e){error.textContent=e.message;error.hidden=false;}finally{submit.disabled=false;}
 });
});
</script>

@endsection
