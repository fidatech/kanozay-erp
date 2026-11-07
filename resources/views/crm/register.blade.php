@extends('layouts.kanozay')
@section('content')
<style>
.crm-workspace{--crm-line:#dce7f3;--crm-ink:#193b61}
.crm-workspace .crm-header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.crm-workspace .crm-header h1{font-size:24px;margin:0 0 7px;color:var(--crm-ink)}
.crm-workspace .crm-header p{margin:0;color:#6e8097;font-size:12px}
.crm-workspace .crm-grid{display:grid;grid-template-columns:minmax(280px,390px) minmax(0,1fr);gap:16px;align-items:start}
.crm-workspace .crm-panel{padding:21px;border:1px solid var(--crm-line);border-radius:12px;background:#fff;box-shadow:0 5px 18px #1d497d07}
.crm-workspace .crm-panel h2{font-size:17px;margin:0 0 17px;color:var(--crm-ink)}
.crm-workspace .crm-form{display:grid!important;grid-template-columns:1fr 1fr;gap:12px!important;align-items:start!important}
.crm-workspace .crm-form label{display:grid;gap:6px;font-size:12px;color:#395878;font-weight:700;min-width:0}
.crm-workspace .crm-form label.span{grid-column:1/-1}
.crm-workspace .crm-form input,.crm-workspace .crm-form select,.crm-workspace .crm-form textarea{display:block;width:100%;min-width:0;border:1px solid #d6e3ef;border-radius:8px;padding:10px;min-height:40px;background:#fff;color:#24436a;font-size:12px}
.crm-workspace .crm-form textarea{height:80px;resize:vertical}
.crm-workspace .crm-form .crm-actions{grid-column:1/-1;display:flex;gap:8px}
.crm-workspace .crm-form .crm-actions button{background:#145fa8;border:0;color:#fff;border-radius:8px;padding:11px 16px;font-weight:700}
.crm-workspace .crm-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:13px}
.crm-workspace .crm-filters input{min-height:41px;flex:1;min-width:190px;border:1px solid var(--crm-line);padding:10px;border-radius:8px}
.crm-workspace .crm-filters button,.crm-workspace .crm-link{border:1px solid #bbd3ec;color:#155a97;background:#ecf5ff;border-radius:8px;padding:10px 13px;text-decoration:none;font-size:12px;font-weight:700}
.crm-workspace .crm-table{overflow:auto;border:1px solid var(--crm-line);border-radius:10px}
.crm-workspace table{min-width:650px;width:100%;font-size:11px;margin:0}
.crm-workspace th{background:#f3f7fc;color:#37597b;padding:11px;text-align:left;white-space:nowrap}
.crm-workspace td{padding:11px;vertical-align:top;max-width:230px;overflow-wrap:anywhere}
.crm-workspace td.actions{white-space:nowrap}
.crm-workspace td.actions form{display:inline!important}
.crm-workspace td.actions button{border:1px solid #f0cccc;background:#fff4f4;color:#b43232;padding:8px 10px;border-radius:7px;cursor:pointer}
.crm-workspace td.actions a{display:inline-block;margin:2px 6px 2px 0;text-decoration:none;font-weight:700;color:#165eaa}
.crm-workspace .crm-help{font-size:12px;color:#71839a;line-height:1.7}
.crm-workspace .crm-error{padding:12px;background:#fff3f1;color:#aa3326;border-radius:9px;margin-bottom:15px}
@media(max-width:1200px){.crm-workspace .crm-grid{grid-template-columns:1fr}}
@media(max-width:600px){.crm-workspace .crm-form{grid-template-columns:1fr}.crm-workspace .crm-panel{padding:13px}}
</style>
<div class="crm-workspace">
<div class="crm-header"><div><h1><i class="fa-solid fa-address-book"></i> {{ $definition['heading'] }}</h1><p>Customer Relationship Management / {{ $definition['heading'] }}</p></div><span class="status">{{ number_format($records->total()) }} records</span></div>
@if(session('success'))<div class="crm-panel" style="margin-bottom:15px;border-color:#a9ddc6;color:#16744c">{{ session('success') }}</div>@endif
@if($errors->any())<div class="crm-error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<div class="crm-grid">
<div class="crm-panel">
@php($isEdit=$editRecord!==null)
<h2>{{ $isEdit ? 'Edit record' : 'New record' }}</h2>
@if($isEdit)<p class="crm-help">Changes to CRM records are audited. Financial and inventory entries are never changed by this form.</p>@endif
@canany(['crm.create','sales.create','administration.update'])
<form class="crm-form" method="POST" action="{{ $isEdit ? route('crm.records.update',[$page,$editRecord->id]) : route('crm.records.store',$page) }}">
@csrf
@if($isEdit)@method('PATCH')@endif
@foreach($definition['fields'] as $name=>$field)
@php($type=$field['type']??'text')
@php($value=old($name,$isEdit ? ($editRecord->{$name}??null) : ($field['default']??null)))
<label class="{{ in_array($type,['textarea'],true)?'span':'' }}">{{ $field['label'] }}@if($field['required']??false)<span style="color:#a92727">*</span>@endif
@if($type==='textarea')
<textarea name="{{ $name }}" maxlength="5000" {{ ($field['required']??false)?'required':'' }}>{{ $value }}</textarea>
@elseif($type==='select')
<select name="{{ $name }}" {{ ($field['required']??false)?'required':'' }}>
@if(!($field['required']??false))<option value="">Select...</option>@endif
@foreach($field['options'] as $option=>$label)<option value="{{ $option }}" @selected((string)$value===(string)$option)>{{ $label }}</option>@endforeach
</select>
@elseif($type==='relation')
<select name="{{ $name }}" {{ ($field['required']??false)?'required':'' }}>
<option value="">Select...</option>
@foreach($lookups[$name]??[] as $id=>$label)<option value="{{ $id }}" @selected((string)$value===(string)$id)>{{ $label }}</option>@endforeach
</select>
@else
<input name="{{ $name }}" type="{{ match($type){'date'=>'date','datetime'=>'datetime-local','percentage'=>'number','money'=>'text','email'=>'email',default=>'text'} }}"
value="{{ $type==='datetime' && $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i') : $value }}"
@if($type==='percentage')min="0" max="100" step="1"@endif
@if($type==='money')inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,4})?"@endif
maxlength="{{ in_array($type,['date','datetime','percentage'],true)?'36':'180' }}"
{{ ($field['required']??false)?'required':'' }}>
@endif
</label>
@endforeach
<div class="crm-actions"><button type="submit"><i class="fa-solid fa-check"></i> {{ $isEdit?'Save changes':'Create record' }}</button>
@if($isEdit)<a class="crm-link" href="{{ route('workspace.page',['crm',$page]) }}">Cancel</a>@endif
</div>
</form>
@else
<p class="crm-help">You have view-only access. Contact an administrator for create or update permission.</p>
@endcanany
</div>
<div class="crm-panel">
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>{{ $definition['heading'] }} register</h2><a class="crm-link" href="{{ route('workspace.page',['crm',$page,'export'=>'csv','search'=>$filters['search']??'']) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
<form class="crm-filters" method="GET" action="{{ route('workspace.page',['crm',$page]) }}"><input type="search" name="search" maxlength="100" value="{{ $filters['search']??'' }}" placeholder="Search name, reference or contact">
<button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button><a class="crm-link" href="{{ route('workspace.page',['crm',$page]) }}">Reset</a></form>
<div class="crm-table"><table><thead><tr>@foreach($definition['columns'] as $column)<th>{{ $definition['fields'][$column]['label']??ucwords(str_replace('_',' ',$column)) }}</th>@endforeach<th>Actions</th></tr></thead><tbody>
@forelse($records as $record)
<tr>@foreach($definition['columns'] as $column)<td>{{ \Illuminate\Support\Str::limit($format($record,$column),85) }}</td>@endforeach
<td class="actions">
@canany(['crm.update','sales.update','administration.update'])
<a href="{{ route('workspace.page',['crm',$page,'edit'=>$record->id]) }}"><i class="fa-solid fa-pen"></i> Edit</a>
@endcanany
@canany(['crm.delete','sales.delete','administration.update'])
<form action="{{ route('crm.records.delete',[$page,$record->id]) }}" method="POST" onsubmit="return confirm('Archive this CRM record? Its history will be preserved.')">@csrf @method('DELETE')
<button type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button></form>
@endcanany</td></tr>
@empty<tr><td colspan="{{ count($definition['columns'])+1 }}" style="text-align:center;padding:30px;color:#7286a1">No CRM records match this search.</td></tr>
@endforelse
</tbody></table></div>{{ $records->links() }}
<p class="crm-help">Related customers, leads, opportunities, assignments and currencies are selected from existing records. Records can be archived without deleting their audit history.</p>
</div></div></div>
@endsection
