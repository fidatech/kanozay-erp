@extends('layouts.kanozay')
@section('content')
<style>
.od-panel{background:white;border:1px solid #dde7f1;border-radius:12px;padding:20px;margin:14px 0}
.od-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:end}
.od-grid label{display:grid;gap:7px;font-size:12px;font-weight:700;color:#47627d}
.od-grid input,.od-grid textarea,.od-grid select{width:100%;box-sizing:border-box;border:1px solid #d6e1ec;border-radius:8px;padding:10px}
.od-grid label.wide{grid-column:1/-1}
.od-dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}
.od-dl dt{font-size:11px;text-transform:uppercase;color:#798ca0;margin-bottom:7px}
.od-dl dd{margin:0;word-break:break-word}
.od-history{overflow:auto}.od-history table{width:100%;min-width:600px;font-size:12px}
.od-history th,.od-history td{padding:12px;border-bottom:1px solid #e6edf4;text-align:left}
.od-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.od-actions form{display:flex;align-items:end;gap:8px;flex-wrap:wrap}
.od-actions textarea{max-width:320px}
@media(max-width:800px){.od-grid,.od-dl{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $record->number }} · {{ $definition['label'] }}</h1>
<p>{{ $definition['purpose'] }}</p></div>
<a class="btn btn-light" href="{{ route('workspace.page',[$module,$page]) }}">Return to register</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<section class="od-panel">
<h2>Record details · {{ ucfirst($record->status) }}</h2>
<dl class="od-dl">
@foreach($definition['fields'] as $name=>$field)
 <div><dt>{{ $field['label'] }}</dt>
 @php($value=$record->data[$name]??null)
 <dd>
 @if($field['type']==='lookup')
  {{ collect($options[$field['source']]??[])->firstWhere('id',(int)$value)['label']??($value??'—') }}
 @elseif($field['type']==='option'){{ $field['options'][$value]??'—' }}
 @else{{ $value??'—' }}@endif
 </dd></div>
@endforeach
</dl>
<p>Entered by {{ $record->creator?->name }} · Last reviewed by {{ $record->reviewer?->name??'No reviewer' }}.</p>
@if($record->review_note)<p><strong>Review note:</strong> {{ $record->review_note }}</p>@endif
</section>
@if($record->status==='draft' &&
 (auth()->user()->id===$record->created_by || auth()->user()->can('administration.update')))
@canany([$module.'.create',$module.'.update','administration.update'])
<section class="od-panel"><details><summary><strong>Edit draft record</strong></summary>
<form class="od-grid" method="POST" action="{{ route('operations.update',[$module,$page,$record]) }}" style="margin-top:15px">@csrf @method('PUT')
@foreach($definition['fields'] as $name=>$field)
<label class="{{ $field['type']==='textarea'?'wide':'' }}">{{ $field['label'] }}
@if($field['type']==='lookup')
<select name="data[{{ $name }}]" @if($field['required']??false) required @endif>
<option value="">Select</option>@foreach($options[$field['source']]??[] as $choice)
<option value="{{ $choice['id'] }}" @selected((old('data.'.$name,$record->data[$name]??null))==$choice['id'])>{{ $choice['label'] }}</option>
@endforeach</select>
@elseif($field['type']==='option')
<select name="data[{{ $name }}]" @if($field['required']??false) required @endif>
<option value="">Select</option>@foreach($field['options'] as $choice=>$text)
<option value="{{ $choice }}" @selected(old('data.'.$name,$record->data[$name]??null)===$choice)>{{ $text }}</option>
@endforeach</select>
@elseif($field['type']==='textarea')
<textarea name="data[{{ $name }}]" rows="3" maxlength="5000">{{ old('data.'.$name,$record->data[$name]??null) }}</textarea>
@else
<input name="data[{{ $name }}]" type="{{ in_array($field['type'],['date','time'],true)?$field['type']:'text' }}"
 @if($field['type']==='decimal') inputmode="decimal" @elseif($field['type']==='integer') inputmode="numeric" @endif
 value="{{ old('data.'.$name,$record->data[$name]??null) }}" @if($field['required']??false) required @endif>
@endif</label>
@endforeach
<button class="btn">Save draft edits</button>
</form>
</details></section>
@endcanany
@endif
@if($module==='purchases' && $page==='quotations' && $record->status==='approved')
@canany(['purchases.create','administration.update'])
<section class="od-panel"><h2>Convert approved supplier quotation to draft PO</h2>
<p>Conversion preserves the reviewed material, currency, quantity and supplier unit price. A purchase order stays in draft until procurement confirms it.</p>
<form method="POST" action="{{ route('supplier-quotations.convert',$record) }}" class="od-grid">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Purchase source<select name="source" required><option value="local">Local purchase</option><option value="foreign">Foreign purchase</option></select></label>
<label>Order date<input type="date" name="order_date" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></label>
<label>Expected delivery date<input type="date" name="expected_date"></label>
<label class="wide">Procurement notes<textarea name="notes" rows="2" maxlength="2000"></textarea></label>
<div><button type="submit" class="btn" onclick="return confirm('Create a new draft PO from this approved offer?')">Convert to draft purchase order</button></div>
</form></section>
@endcanany
@endif
<section class="od-panel"><h2>Approval workflow</h2>
<p class="muted">Approvers cannot approve their own records. A closed record stays visible with its complete change history. This approval does not itself create stock or financial postings.</p>
<div class="od-actions">
@if($record->status==='draft' && (auth()->user()->id===$record->created_by||auth()->user()->can('administration.update')))
@canany([$module.'.create',$module.'.update','administration.update'])
<form method="POST" action="{{ route('operations.transition',[$module,$page,$record]) }}">@csrf
<input type="hidden" name="decision" value="submit"><button class="btn">Submit for review</button></form>
<form method="POST" action="{{ route('operations.transition',[$module,$page,$record]) }}">@csrf
<input type="hidden" name="decision" value="cancel"><button class="btn btn-light">Cancel draft</button></form>
@endcanany
@endif
@if($record->status==='submitted'&&$record->created_by!==auth()->user()->id)
@canany([$module.'.update','administration.update'])
<form method="POST" action="{{ route('operations.transition',[$module,$page,$record]) }}">@csrf
<input name="note" maxlength="1500" placeholder="Approval reference / note">
<button class="btn" name="decision" value="approve">Approve</button>
<button class="btn btn-light" name="decision" value="reject">Reject</button>
</form>
@endcanany
@endif
@if($record->status==='rejected'&&
 (auth()->user()->id===$record->created_by||auth()->user()->can('administration.update')))
@canany([$module.'.create',$module.'.update','administration.update'])
<form method="POST" action="{{ route('operations.transition',[$module,$page,$record]) }}">@csrf
<button class="btn" name="decision" value="revise">Revise and resubmit</button>
<button class="btn btn-light" name="decision" value="cancel">Cancel record</button></form>
@endcanany
@endif
@if($record->status==='approved')
@if($module==='hr' && $page==='exits')
<p><strong>Closing this approved exit deactivates the employee profile.</strong> Both HR update and Finance update authorization are required. Outstanding assets, employee advances and unresolved payroll drafts must be cleared.</p>
@endif
@canany([$module.'.update','administration.update'])
<form method="POST" action="{{ route('operations.transition',[$module,$page,$record]) }}">@csrf
<input name="note" maxlength="1500" placeholder="Completion reference">
<button class="btn" name="decision" value="close">Close operational record</button>
</form>
@endcanany
@endif
</div></section>
@if(($linkedLandedCosts ?? null) !== null)
<section class="od-panel"><h2>Landed costs recorded against this shipment</h2>
<div class="od-history"><table><thead><tr><th>Goods receipt</th><th>Component</th><th style="text-align:end">Amount</th><th>Currency</th><th>Reference</th><th>General ledger</th></tr></thead><tbody>
@forelse($linkedLandedCosts as $cost)
<tr><td><a href="{{ route('purchases.orders.show',$cost->purchase_order_id) }}">{{ $cost->receipt_number }}</a></td><td>{{ ucfirst($cost->component) }}</td><td style="text-align:end;font-variant-numeric:tabular-nums">{{ number_format((float)$cost->amount,2) }}</td><td>{{ $cost->currency_code }}</td><td>{{ $cost->reference }}</td><td>{{ $cost->posting_id ? 'Capitalized' : 'Awaiting capitalization' }}</td></tr>
@empty<tr><td colspan="6">No landed cost has been linked to this shipment. Link it when recording the charge on the goods receipt.</td></tr>@endforelse
</tbody>
@if($linkedLandedCosts->isNotEmpty())
<tfoot>@foreach($linkedLandedCosts->groupBy('currency_code') as $code=>$group)<tr><th colspan="2">Total {{ $code }}</th><th style="text-align:end;font-variant-numeric:tabular-nums">{{ number_format((float)$group->sum('amount'),2) }}</th><th colspan="3"></th></tr>@endforeach</tfoot>
@endif
</table></div></section>
@endif
<section class="od-panel"><h2>Record history</h2><div class="od-history"><table><thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Note</th></tr></thead><tbody>
@forelse($record->events as $event)
<tr><td>{{ $event->created_at?->format('Y-m-d H:i') }}</td><td>{{ ucfirst($event->action) }}</td>
<td>{{ $event->actor?->name }}</td><td>{{ $event->note??'—' }}</td></tr>
@empty<tr><td colspan="4">No recorded events.</td></tr>@endforelse</tbody></table></div></section>
@endsection
