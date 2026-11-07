@extends('layouts.kanozay')
@section('content')
<style>
.ops-panel{background:white;border:1px solid #dde7f1;border-radius:12px;padding:20px;margin:14px 0}
.ops-panel h2{font-size:17px;margin:0 0 12px}
.ops-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:end}
.ops-form label{display:grid;gap:7px;font-size:12px;color:#47627d;font-weight:700}
.ops-form input,.ops-form select,.ops-form textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d6e1ec;border-radius:8px;background:white;min-height:42px;font:inherit}
.ops-form label.long{grid-column:span 2}
.ops-form label.wide{grid-column:1/-1}
.ops-table{overflow:auto;border:1px solid #e0e8f1;border-radius:9px}
.ops-table table{min-width:790px;font-size:12px;width:100%;border-collapse:collapse}
.ops-table th,.ops-table td{padding:12px;border-bottom:1px solid #e9eef3;vertical-align:top}
.ops-table th{background:#f3f7fb;text-align:left}
.ops-notice{padding:13px;border-radius:8px;background:#fff8eb;color:#795e2e;font-size:12px;line-height:1.7}
.ops-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0}
.ops-toolbar input,.ops-toolbar select{padding:10px;border:1px solid #d8e3ef;border-radius:8px}
.ops-status{display:inline-block;background:#eaf1f8;padding:3px 8px;border-radius:7px;font-weight:700;font-size:11px}
@media(max-width:1000px){.ops-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.ops-form{grid-template-columns:1fr}.ops-form label.long{grid-column:1}.ops-toolbar>*{width:100%}}
</style>
<div class="heading">
 <div><h1>{{ $definition['label'] }}</h1><p>{{ config('erp_pages.'.$module.'.label') }} / {{ $definition['label'] }} operational register</p></div>
 <a href="{{ route('operations.export',[$module,$page]) }}" class="btn btn-light">Export CSV</a>
</div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ $errors->first() }}</p>@endif
<div class="ops-notice">{{ $definition['purpose'] }} A submitted record requires another authorized user to approve it. Changes here never silently post General Ledger, stock, salaries, tax or supplier settlements.</div>
@canany([$module.'.create',$module.'.update','administration.update'])
<section class="ops-panel">
 <h2>Create {{ $definition['label'] }} record</h2>
 <form method="POST" action="{{ route('operations.store',[$module,$page]) }}" class="ops-form">@csrf
 <input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
 @foreach($definition['fields'] as $name=>$field)
 <label class="{{ $field['type']==='textarea'?'wide':'' }}">
  {{ $field['label'] }}
  @if($field['type']==='option')
   <select name="data[{{ $name }}]" @if($field['required']??false) required @endif><option value="">Choose an option</option>
   @foreach($field['options'] as $value=>$label)<option value="{{ $value }}" @selected(old('data.'.$name)===$value)>{{ $label }}</option>@endforeach
   </select>
  @elseif($field['type']==='lookup')
   <select name="data[{{ $name }}]" @if($field['required']??false) required @endif><option value="">Choose {{ strtolower($field['label']) }}</option>
   @foreach($options[$field['source']]??[] as $choice)
    <option value="{{ $choice['id'] }}" @selected(old('data.'.$name)==$choice['id'])>{{ $choice['label'] }}</option>
   @endforeach
   </select>
  @elseif($field['type']==='textarea')
   <textarea name="data[{{ $name }}]" rows="3" maxlength="5000" @if($field['required']??false) required @endif>{{ old('data.'.$name) }}</textarea>
  @else
   <input
    name="data[{{ $name }}]"
    type="{{ in_array($field['type'],['date','time','datetime'],true)?($field['type']==='datetime'?'datetime-local':$field['type']):'text' }}"
    @if($field['type']==='decimal') inputmode="decimal" @elseif($field['type']==='integer') inputmode="numeric" @endif
    @if($field['required']??false) required @endif
    @if($field['type']==='text') maxlength="180" @endif
    value="{{ old('data.'.$name) }}"
    placeholder="{{ $field['label'] }}"
   >
  @endif
 </label>
 @endforeach
 <div><button type="submit" class="btn">Save draft record</button></div>
 </form>
</section>
@endcanany
<section class="ops-panel">
 <h2>Recorded {{ $definition['label'] }}</h2>
 <form method="GET" class="ops-toolbar">
  <input name="q" placeholder="Search reference or record" value="{{ request('q') }}" maxlength="100">
  <select name="status">
    <option value="">All states</option>
    @foreach(['draft','submitted','approved','rejected','closed','cancelled'] as $state)
     <option value="{{ $state }}" @selected(request('status')===$state)>{{ ucfirst($state) }}</option>
    @endforeach
  </select>
  <button class="btn btn-secondary">Filter</button>
  <a class="btn btn-light" href="{{ route('workspace.page',[$module,$page]) }}">Clear</a>
 </form>
 <div class="ops-table"><table><thead><tr>
 <th>Reference</th><th>Status</th>
 @foreach(array_slice($definition['fields'],0,3,true) as $field)<th>{{ $field['label'] }}</th>@endforeach
 <th>Creator / Review</th><th>Action</th>
 </tr></thead><tbody>
 @forelse($records as $record)
 <tr>
  <td><strong>{{ $record->number }}</strong><br><small>{{ $record->created_at?->format('Y-m-d') }}</small></td>
  <td><span class="ops-status">{{ ucfirst($record->status) }}</span></td>
  @foreach(array_slice($definition['fields'],0,3,true) as $key=>$field)
   <td>
   @php($value=$record->data[$key]??null)
   @if($field['type']==='lookup')
    {{ collect($options[$field['source']]??[])->firstWhere('id',(int)$value)['label']??($value??'—') }}
   @elseif($field['type']==='option'){{ $field['options'][$value]??'—' }}
   @else{{ $value??'—' }}@endif
   </td>
  @endforeach
  <td>{{ $record->creator?->name }}<br><small>{{ $record->reviewer?->name??'Not reviewed' }}</small></td>
  <td><a href="{{ route('operations.show',[$module,$page,$record]) }}">View / Review</a></td>
 </tr>
 @empty<tr><td colspan="{{ count(array_slice($definition['fields'],0,3))+4 }}">No records yet.</td></tr>@endforelse
 </tbody></table></div>
 {{ $records->links() }}
</section>
@endsection
