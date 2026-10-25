@extends('layouts.kanozay')
@section('content')
<style>
.finance-wrap{max-width:1050px}.finance-form{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px!important}.finance-form label{display:grid;gap:7px;color:#2b456c;font-weight:700;font-size:12px}.finance-form input,.finance-form select{width:100%;height:44px;border:1px solid #d6e2f1;border-radius:8px;padding:10px 12px;background:#fff;color:#263e62;font-size:13px}.finance-help{color:#70819e;font-size:12px;line-height:1.6}.finance-icon{color:#21875e;background:#e4f7eb;padding:10px;border-radius:8px}@media(max-width:700px){.finance-form{grid-template-columns:1fr}}
</style>
<div class="finance-wrap">
<div class="heading"><div><h1>Financial Configuration</h1><p>Manage accounting, purchasing and operational controls.</p></div></div>
@if($errors->any())<section class="panel" style="color:#b42318">{{ $errors->first() }}</section>@endif
<section class="panel"><h2><i class="fa-solid fa-sliders finance-icon"></i> Accounting & Operational Settings</h2>
<form class="finance-form" method="POST" action="{{ route('admin.financial-settings.update') }}">
@csrf @method('PUT')
<label>Base Currency<select class="enhanced-select" name="base_currency_id"><option value="">Not configured</option>
@foreach($currencies as $currency)
<option value="{{ $currency->id }}" @selected(old('base_currency_id',$setting?->base_currency_id ?? \App\Models\Currency::where('is_base',true)->value('id'))==$currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>
@endforeach
</select><span class="finance-help">Must match the selected accounting base currency.</span></label>
@php($fiscalParts = explode('-', old('fiscal_year_start', $setting?->fiscal_year_start ?? '01-01')))
<label>Fiscal Year Start <span style="display:flex;gap:10px"><select id="fiscal-month" aria-label="Fiscal year start month" class="enhanced-select">@foreach(range(1,12) as $month)<option value="{{ sprintf('%02d',$month) }}" @selected(($fiscalParts[0] ?? '01')===sprintf('%02d',$month))>{{ \Carbon\Carbon::create(2024,$month,1)->format('F') }}</option>@endforeach</select><select id="fiscal-day" aria-label="Fiscal year start day" class="enhanced-select">@foreach(range(1,31) as $day)<option value="{{ sprintf('%02d',$day) }}" @selected(($fiscalParts[1] ?? '01')===sprintf('%02d',$day))>{{ $day }}</option>@endforeach</select></span><input type="hidden" name="fiscal_year_start" id="fiscal-value" value="{{ old('fiscal_year_start',$setting?->fiscal_year_start ?? '01-01') }}"><span class="finance-help">Choose the starting month and day.</span></label>
<label>Purchasing Mode<select class="enhanced-select" name="purchase_mode">
@foreach(['both'=>'Local and International','local'=>'Local Only','international'=>'International Only'] as $key=>$label)
<option value="{{ $key }}" @selected(old('purchase_mode',$setting?->purchase_mode ?? 'both')===$key)>{{ $label }}</option>
@endforeach
</select></label>
<label>Negative Stock<select class="enhanced-select" name="allow_negative_stock"><option value="0" @selected(!old('allow_negative_stock',$setting?->allow_negative_stock ?? false))>Disallow</option><option value="1" @selected(old('allow_negative_stock',$setting?->allow_negative_stock ?? false))>Allow</option></select></label>
<label>Purchase Approval<select class="enhanced-select" name="require_purchase_approval"><option value="1" @selected(old('require_purchase_approval',$setting?->require_purchase_approval ?? true))>Required</option><option value="0" @selected(!old('require_purchase_approval',$setting?->require_purchase_approval ?? true))>Not Required</option></select></label>
<label>Payment Approval<select class="enhanced-select" name="require_payment_approval"><option value="1" @selected(old('require_payment_approval',$setting?->require_payment_approval ?? true))>Required</option><option value="0" @selected(!old('require_payment_approval',$setting?->require_payment_approval ?? true))>Not Required</option></select></label>
<div style="grid-column:1/-1"><p class="finance-help">These settings will be enforced when the purchasing, inventory and payment modules are connected.</p>
@can('administration.update')<button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Financial Settings</button>@endcan
</div></form></section></div>
<script>
document.addEventListener('DOMContentLoaded',function(){const m=document.getElementById('fiscal-month'),d=document.getElementById('fiscal-day'),v=document.getElementById('fiscal-value');function sync(){const days=new Date(2024,Number(m.value),0).getDate();[...d.options].forEach(o=>o.disabled=Number(o.value)>days);if(Number(d.value)>days)d.value=String(days).padStart(2,'0');v.value=m.value+'-'+d.value;}m.addEventListener('change',sync);d.addEventListener('change',sync);sync();});
</script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"><script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script><script>document.addEventListener('DOMContentLoaded',function(){if(window.jQuery&&jQuery.fn.select2)jQuery('.enhanced-select').select2({width:'100%'});});</script>
@endsection