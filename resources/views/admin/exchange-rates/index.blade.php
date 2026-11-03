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
/* Pair cards: readable, independent direction entries with inline editing. */
.fx-pair-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,292px),1fr));gap:15px;align-items:start}
.fx-pair-card{position:relative;display:flex;flex-direction:column;min-width:0;gap:0;overflow:hidden;border:1px solid #dce7f3;border-radius:16px;background:#fff;box-shadow:0 3px 12px rgba(25,55,90,.035);transition:border-color .18s,box-shadow .18s,transform .18s}
.fx-pair-card:hover{border-color:#aacbe9;box-shadow:0 10px 22px rgba(24,70,120,.075)}
.fx-pair-card[hidden],.fx-inline-form[hidden],.fx-pair-display[hidden],.fx-pair-rate[hidden],.fx-pair-missing[hidden],.fx-error[hidden]{display:none!important}
.fx-pair-top{padding:17px 18px 13px;display:flex;justify-content:space-between;align-items:start;gap:9px;border-bottom:1px solid #eff3f8}
.fx-pair-kicker{margin:0 0 8px;color:#7a8ba1;font-size:10px;font-weight:750;text-transform:uppercase;letter-spacing:.09em}
.fx-pair-codes{display:flex;align-items:center;gap:9px;min-width:0;font-size:14px;font-weight:800;color:#163c6e;letter-spacing:.02em}
.fx-pair-codes strong{padding:7px 9px;background:#eef5ff;border:1px solid #e2edfb;border-radius:8px;white-space:nowrap}
.fx-pair-codes i{font-size:11px;color:#7e97b2}
.fx-pair-status{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 9px;border-radius:20px;white-space:nowrap;font-size:10px;font-weight:800;background:#edf8f2;color:#177b50}
.fx-pair-status.is-unset{background:#fff6e7;color:#9a671e}.fx-pair-status i{font-size:7px}
.fx-pair-display{padding:18px 18px 15px;min-height:136px;display:flex;flex-direction:column;justify-content:center;gap:8px}
.fx-rate-label{color:#71849d;font-size:11px;font-weight:700}
.fx-pair-rate{font-size:clamp(22px,2vw,28px);line-height:1.25;letter-spacing:-.035em;font-weight:850;color:#123f77;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
.fx-pair-missing{font-size:22px;font-weight:800;color:#9d702e;letter-spacing:-.02em}
.fx-pair-equation{font-size:12px;color:#496784;font-weight:650;line-height:1.5}
.fx-pair-meta{font-size:11px;color:#74869a;line-height:1.55;overflow-wrap:anywhere}
.fx-pair-footer{display:flex;flex-direction:column;gap:12px;margin-top:auto;padding:13px 18px 17px;background:#f8fbff;border-top:1px solid #edf2f8}
.fx-meta-label{font-size:10px;text-transform:uppercase;letter-spacing:.055em;color:#93a1b3;font-weight:700;margin:0 0 4px}
.fx-effective-date{min-height:18px}
.fx-pair-set{display:inline-flex;justify-content:center;align-items:center;gap:8px;width:100%;min-height:42px;border:1px solid #bdd6ef;border-radius:10px;background:#eaf4ff;color:#125c9f;font-weight:750;cursor:pointer;transition:background .15s,border-color .15s}
.fx-pair-set:hover{background:#d9ecff;border-color:#83b8e8}.fx-pair-set:focus-visible,.fx-inline-actions button:focus-visible{outline:3px solid #79b8f9;outline-offset:2px}
.fx-pair-set[aria-expanded=true]{background:#155b9d;border-color:#155b9d;color:#fff}.fx-pair-set[aria-expanded=true] i{transform:rotate(180deg)}
.fx-inline-form{display:grid;gap:13px;padding:17px 18px 20px;background:#fff;border-top:1px solid #dce7f5}
.fx-inline-form label{display:grid;gap:6px;color:#304d72;font-size:12px;font-weight:700;line-height:1.4}
.fx-inline-form input:not([type=hidden]),.fx-inline-form textarea{width:100%;min-height:42px;border:1px solid #d2dfed;border-radius:9px;background:#fff;padding:10px 12px;font:inherit;font-weight:500;color:#173f68;box-sizing:border-box;outline:0}
.fx-inline-form input:focus,.fx-inline-form textarea:focus{border-color:#428fdb;box-shadow:0 0 0 3px rgba(52,137,218,.13)}
.fx-inline-form textarea{min-height:72px;resize:vertical}.fx-inline-form .fx-form-hint{font-size:11px;color:#7689a0;font-weight:400}
.fx-inline-actions{display:flex;align-items:center;gap:9px;margin-top:2px}.fx-inline-actions button{flex:1;min-height:42px;padding:10px;border:1px solid #bed3e8;border-radius:10px;font-weight:700;cursor:pointer}
.fx-save{background:#155b9d;color:#fff;border-color:#155b9d!important}.fx-save:hover{background:#104a83}.fx-save:disabled{opacity:.65;cursor:wait}.fx-cancel{background:#fff;color:#385a7d}.fx-cancel:hover{background:#f3f7fc}
.fx-error{margin:0;color:#b42318;font-size:12px;line-height:1.5}.fx-history-wrapper{margin-top:18px}.fx-pair-card.is-saving{border-color:#70b4eb}
@media(max-width:650px){.fx-pair-tools{width:100%}.fx-pair-search{flex:1}.fx-pair-search input{width:100%}.fx-pair-board{grid-template-columns:1fr}.fx-pair-card{border-radius:13px}}
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
 <article class="fx-pair-card" data-pair="{{ strtolower($source->code.' '.$destination->code) }}" data-from="{{ $source->id }}" data-to="{{ $destination->id }}" aria-label="{{ $source->code }} to {{ $destination->code }} exchange rate">
  <div class="fx-pair-top">
   <div><p class="fx-pair-kicker">Currency direction</p><div class="fx-pair-codes"><strong>{{ $source->code }}</strong><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><strong>{{ $destination->code }}</strong></div></div>
   <span class="fx-pair-status {{ $pairRate ? '' : 'is-unset' }}"><i class="fa-solid fa-circle" aria-hidden="true"></i><span>{{ $pairRate ? 'Recorded' : 'Not set' }}</span></span>
  </div>
  <div class="fx-pair-display" aria-live="polite">
   <div class="fx-rate-label">Current recorded rate</div>
   <div class="fx-pair-rate" @if(!$pairRate) hidden @endif>{{ $pairRate ? rtrim(rtrim((string)$pairRate->rate,'0'),'.') : '' }}</div>
   <div class="fx-pair-missing" @if($pairRate) hidden @endif>No rate yet</div>
   <div class="fx-pair-equation">1 {{ $source->code }} = <span class="fx-equation-value">{{ $pairRate ? rtrim(rtrim((string)$pairRate->rate,'0'),'.') : '—' }}</span> {{ $destination->code }}</div>
  </div>
  <div class="fx-pair-footer">
   <div><p class="fx-meta-label">Effective date · Source</p><div class="fx-pair-meta fx-effective-date">@if($pairRate){{ $pairRate->effective_date->format('Y-m-d') }} · {{ $pairRate->source ?: 'Manual' }}@else No recorded rate for this direction @endif</div></div>
   @can('administration.update')<button type="button" class="fx-pair-set" aria-expanded="false" aria-controls="fx-form-{{ $source->id }}-{{ $destination->id }}"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span>{{ $pairRate ? 'Update rate' : 'Set rate' }}</span></button>@endcan
  </div>
  @can('administration.update')
   <form id="fx-form-{{ $source->id }}-{{ $destination->id }}" method="POST" action="{{ route('admin.exchange-rates.store') }}" class="fx-inline-form" hidden>
    @csrf
    <input type="hidden" name="from_currency_id" value="{{ $source->id }}">
    <input type="hidden" name="to_currency_id" value="{{ $destination->id }}">
    <label>Rate · 1 {{ $source->code }} in {{ $destination->code }}<input type="text" name="rate" inputmode="decimal" autocomplete="off" required pattern="[0-9]+(\.[0-9]{1,12})?" placeholder="Enter positive rate" value="{{ $pairRate ? rtrim(rtrim((string)$pairRate->rate,'0'),'.') : '' }}"><span class="fx-form-hint">Up to 12 decimal places</span></label>
    <label>Effective date<input type="date" name="effective_date" required value="{{ now()->toDateString() }}"></label>
    <label>Rate source<input name="source" maxlength="150" placeholder="Bank / Sarafi / Agreed" value="{{ $pairRate?->source }}"></label>
    <label>Notes (optional)<textarea name="notes" maxlength="1000" rows="2" placeholder="Internal reference or context"></textarea></label>
    <p class="fx-error" role="alert" hidden></p>
    <div class="fx-inline-actions"><button class="fx-cancel" type="button">Cancel</button><button class="fx-save" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save rate</button></div>
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
   display.querySelector('.fx-equation-value').textContent=rate.textContent;
   card.querySelector('.fx-effective-date').textContent=current.effective_date+' · '+current.source;
   const status=card.querySelector('.fx-pair-status');status.classList.remove('is-unset');status.querySelector('span').textContent='Recorded';
   form.querySelector('input[name=rate]').value=rate.textContent;
   card.querySelector('.fx-pair-set span').textContent='Update rate';
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
