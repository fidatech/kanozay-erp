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
<style>
.fx-pair-title{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.fx-pair-title h2{margin:0 0 6px}.fx-pair-tools{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}
.fx-pair-search{display:grid;gap:6px;font-size:12px;font-weight:700}.fx-pair-search input{min-height:39px;padding:8px 12px;border:1px solid #cbdced;border-radius:9px}
.fx-pair-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:14px}
.fx-pair-card{border:1px solid #dfe9f5;background:#fbfdff;border-radius:13px;padding:17px;display:flex;flex-direction:column;gap:10px;min-width:0}
.fx-pair-card[hidden]{display:none!important}.fx-pair-codes{display:flex;align-items:center;gap:10px;font-size:14px}.fx-pair-codes i{font-size:11px;color:#7188a3}
.fx-pair-rate{font-size:23px;font-weight:800;color:#1a629a;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.fx-pair-missing{font-size:15px;font-weight:700;color:#996826}.fx-pair-meta{color:#6c8099;font-size:11px;line-height:1.5}
.fx-pair-set{margin-top:auto;border:1px solid #b9d5ed;background:#eaf5ff;color:#185b98;border-radius:9px;padding:10px;cursor:pointer;font-weight:700;min-height:39px}
.fx-pair-set:hover{background:#d8ecff}.fx-inline-form{display:grid;gap:11px;border-top:1px solid #e0ebf6;padding-top:13px;margin-top:2px}
.fx-inline-form[hidden],.fx-pair-display[hidden]{display:none!important}.fx-inline-form label{display:grid;gap:5px;font-weight:650;font-size:12px;color:#34506f}
.fx-inline-form input,.fx-inline-form textarea{min-height:38px;padding:9px 10px;width:100%;border:1px solid #cddfec;border-radius:8px;box-sizing:border-box;font:inherit}
.fx-inline-form textarea{min-height:60px;resize:vertical}.fx-inline-actions{display:flex;gap:8px}.fx-inline-actions button{flex:1;border:1px solid #bad2e9;border-radius:9px;padding:9px;cursor:pointer}
.fx-save{background:#155ca0;color:white;border-color:#155ca0!important}.fx-cancel{background:white;color:#355578}.fx-error{color:#b42318;font-size:12px}
.fx-history-wrapper{margin-top:18px}.fx-pair-card.is-saving{opacity:.7}
@media(max-width:650px){.fx-pair-tools{width:100%}.fx-pair-search{flex:1}.fx-pair-search input{width:100%}}
</style>
<section class="panel fx-pairs">
 <div class="fx-pair-title">
  <div><h2>Currency Pair Rate Board</h2><p class="rate-note">Edit each direction separately. Saving a rate updates only its card and preserves history.</p></div>
  <div class="fx-pair-tools">
   <label class="fx-pair-search">Find pair<input type="search" id="fx-pair-filter" placeholder="USD, AFN, PKR…"></label>
   @can('administration.update')<button type="button" class="btn" id="fx-add-currency"><i class="fa-solid fa-plus"></i> Add Currency</button>@endcan
  </div>
 </div>
 <div class="fx-pair-board" id="fx-pair-board">
 @foreach($currencies as $source)
 @foreach($currencies as $destination)
 @if($source->id !== $destination->id)
 @php($pairRate=$latestRates->get($source->id.':'.$destination->id))
 <article class="fx-pair-card" data-pair="{{ strtolower($source->code.' '.$destination->code) }}" data-from="{{ $source->id }}" data-to="{{ $destination->id }}">
  <div class="fx-pair-codes"><strong>{{ $source->code }}</strong><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><strong>{{ $destination->code }}</strong></div>
  <div class="fx-pair-display">
   <div class="fx-pair-rate" @if(!$pairRate) hidden @endif>{{ $pairRate ? rtrim(rtrim((string)$pairRate->rate,'0'),'.') : '' }}</div>
   <div class="fx-pair-missing" @if($pairRate) hidden @endif>Not set</div>
   <div class="fx-pair-meta">1 {{ $source->code }} = rate in {{ $destination->code }}</div>
   <div class="fx-pair-meta fx-effective-date">@if($pairRate){{ $pairRate->effective_date->format('Y-m-d') }} · {{ $pairRate->source ?: 'Manual' }}@else No rate recorded for this direction @endif</div>
  </div>
  @can('administration.update')
   <button type="button" class="fx-pair-set" aria-expanded="false">{{ $pairRate ? 'Update rate' : 'Set rate' }}</button>
   <form method="POST" action="{{ route('admin.exchange-rates.store') }}" class="fx-inline-form" hidden>
    @csrf
    <input type="hidden" name="from_currency_id" value="{{ $source->id }}">
    <input type="hidden" name="to_currency_id" value="{{ $destination->id }}">
    <label>Rate: 1 {{ $source->code }} = ? {{ $destination->code }}<input type="text" name="rate" inputmode="decimal" autocomplete="off" required pattern="[0-9]+(\.[0-9]{1,12})?" placeholder="Enter positive rate" value="{{ $pairRate ? rtrim(rtrim((string)$pairRate->rate,'0'),'.') : '' }}"></label>
    <label>Effective date<input type="date" name="effective_date" required value="{{ now()->toDateString() }}"></label>
    <label>Rate source<input name="source" maxlength="150" placeholder="Bank / Sarafi / Agreed" value="{{ $pairRate?->source }}"></label>
    <label>Notes (optional)<textarea name="notes" maxlength="1000" rows="2"></textarea></label>
    <p class="fx-error" role="alert" hidden></p>
    <div class="fx-inline-actions"><button class="fx-cancel" type="button">Cancel</button><button class="fx-save" type="submit">Save rate</button></div>
   </form>
  @endcan
 </article>
 @endif
 @endforeach
 @endforeach
 </div>
 @if($currencies->count()<2)<p class="rate-note">Add at least two active currencies to configure rates.</p>@endif
</section>
<div class="fx-history-wrapper">
<section class="panel"><h2>Exchange Rate History</h2><p class="rate-note">Each new rate is retained as a separate historical record; existing rates are not overwritten.</p><div class="table-wrap"><table><thead><tr><th>Currency Pair</th><th>Rate</th><th>Effective Date</th><th>Source</th><th>Recorded By</th></tr></thead><tbody id="fx-rate-history">@forelse($rates as $rate)<tr><td><div class="rate-pair"><strong>{{ $rate->fromCurrency?->code }}</strong><i class="fa-solid fa-arrow-right"></i><strong>{{ $rate->toCurrency?->code }}</strong></div><small class="muted">{{ $rate->notes }}</small></td><td class="rate-value">{{ rtrim(rtrim(number_format((float)$rate->rate,12,'.',''), '0'), '.') }}</td><td class="rate-date">{{ $rate->effective_date->format('d M Y') }}</td><td>{{ $rate->source ?: 'Manual' }}</td><td>{{ $rate->creator?->name ?? '—' }}<br><small class="muted">{{ $rate->created_at->timezone('Asia/Kabul')->format('d M Y H:i') }}</small></td></tr>@empty<tr><td colspan="5" class="empty"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i></div><strong>No exchange rates yet</strong><p>Record a currency conversion rate to begin your rate history.</p></div></td></tr>@endforelse
</tbody></table></div><div style="margin-top:16px">{{ $rates->onEachSide(1)->links('vendor.pagination.kanozay') }}</div></section>
</div>
@can('administration.update')
<dialog id="fx-new-currency" class="k-modal" aria-labelledby="fx-new-currency-title">
<form id="fx-new-currency-form" class="modal-form"><div class="modal-head"><h2 id="fx-new-currency-title">Add Currency</h2><button type="button" class="modal-close" id="fx-currency-close" aria-label="Close">×</button></div>
<div class="modal-body"><label>ISO Currency Code<input name="code" required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" placeholder="e.g. PKR"></label><label>Currency Name<input name="name" required maxlength="100" placeholder="e.g. Pakistani Rupee"></label><label>Symbol<input name="symbol" maxlength="12" placeholder="e.g. Rs"></label></div>
<p id="fx-currency-error" class="error" style="padding:0 20px" role="alert" hidden></p>
<div class="modal-foot"><button type="button" class="btn btn-light" id="fx-currency-cancel">Cancel</button><button class="btn" type="submit">Save and Select</button></div></form></dialog>
@endcan
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const board=document.getElementById('fx-pair-board');
 document.getElementById('fx-pair-filter')?.addEventListener('input',event=>{
  const search=event.target.value.trim().toLowerCase();
  board.querySelectorAll('.fx-pair-card').forEach(card=>{card.hidden=!!search&&!card.dataset.pair.includes(search);});
 });
 board.addEventListener('click',event=>{
  const card=event.target.closest('.fx-pair-card');if(!card)return;
  const toggle=event.target.closest('.fx-pair-set');
  if(toggle){const form=card.querySelector('.fx-inline-form');if(!form)return;form.hidden=!form.hidden;toggle.setAttribute('aria-expanded',String(!form.hidden));if(!form.hidden)form.querySelector('input[name=rate]').focus();}
  if(event.target.closest('.fx-cancel')){const form=card.querySelector('.fx-inline-form');form.hidden=true;card.querySelector('.fx-pair-set').setAttribute('aria-expanded','false');form.querySelector('.fx-error').hidden=true;}
 });
 board.addEventListener('submit',async event=>{
  const form=event.target.closest('.fx-inline-form');if(!form)return;event.preventDefault();
  if(!form.reportValidity())return;
  const card=form.closest('.fx-pair-card'),submit=form.querySelector('.fx-save'),error=form.querySelector('.fx-error');
  error.hidden=true;submit.disabled=true;card.classList.add('is-saving');
  try{
   const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
   const data=await response.json();
   if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'Unable to save rate.');
   const current=data.current,display=card.querySelector('.fx-pair-display');
   const rate=display.querySelector('.fx-pair-rate'),missing=display.querySelector('.fx-pair-missing');
   rate.textContent=current.rate.replace(/0+$/,'').replace(/\.$/,'');rate.hidden=false;missing.hidden=true;
   display.querySelector('.fx-effective-date').textContent=current.effective_date+' · '+current.source;
   form.querySelector('input[name=rate]').value=rate.textContent;
   card.querySelector('.fx-pair-set').textContent='Update rate';
   form.hidden=true;card.querySelector('.fx-pair-set').setAttribute('aria-expanded','false');
   const table=document.getElementById('fx-rate-history');
   const placeholder=table?.querySelector('td.empty')?.closest('tr');if(placeholder)placeholder.remove();
   if(table){const row=document.createElement('tr');
    const pair=document.createElement('td'),value=document.createElement('td'),date=document.createElement('td'),source=document.createElement('td'),person=document.createElement('td');
    const codes=card.querySelectorAll('.fx-pair-codes strong');pair.textContent=codes[0].textContent+' → '+codes[1].textContent;
    value.textContent=data.rate.rate;date.textContent=data.rate.effective_date;source.textContent=data.rate.source;person.textContent=data.rate.recorded_by;
    row.append(pair,value,date,source,person);table.prepend(row);
   }
   if(window.Swal)window.Swal.fire({icon:'success',title:'Rate saved',text:data.message,timer:1800,showConfirmButton:false});
  }catch(err){error.textContent=err.message;error.hidden=false;}
  finally{submit.disabled=false;card.classList.remove('is-saving');}
 });
 const modal=document.getElementById('fx-new-currency');
 if(modal){
  document.getElementById('fx-add-currency')?.addEventListener('click',()=>{modal.querySelector('form').reset();document.getElementById('fx-currency-error').hidden=true;modal.showModal();});
  ['fx-currency-close','fx-currency-cancel'].forEach(id=>document.getElementById(id).addEventListener('click',()=>modal.close()));
  modal.querySelector('form').addEventListener('submit',async event=>{
   event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type=submit]'),error=document.getElementById('fx-currency-error');
   button.disabled=true;error.hidden=true;
   try{
    const data=new FormData(form);data.append('_token',@json(csrf_token()));
    const response=await fetch(@json(route('purchases.quick-currency')),{method:'POST',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:data});
    const payload=await response.json();if(!response.ok)throw new Error(Object.values(payload.errors||{}).flat().join(' ')||payload.message||'Unable to add currency.');
    modal.close();window.location.reload();
   }catch(err){error.textContent=err.message;error.hidden=false;}finally{button.disabled=false;}
  });
 }
});
</script>

@endsection
