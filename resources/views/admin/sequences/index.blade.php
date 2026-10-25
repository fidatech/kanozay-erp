@extends('layouts.kanozay')
@section('content')
<style>
.sequence-layout{display:grid;grid-template-columns:330px minmax(0,1fr);gap:18px}.sequence-form{display:grid!important;gap:14px!important}.sequence-form label{display:grid;gap:7px;font-size:12px;font-weight:700;color:#2c456a}.sequence-form input,.sequence-form select{min-height:43px;width:100%;padding:10px;border:1px solid #d7e3f1;border-radius:7px}.sequence-code{font-family:monospace;color:#2167b5;font-weight:700}.sequence-help{font-size:12px;color:#70819c;line-height:1.6}.sequence-edit{border:1px solid #d7e3f1;background:white;border-radius:7px;padding:8px 12px;cursor:pointer}@media(max-width:900px){.sequence-layout{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-hashtag" style="color:#8957bc"></i> Document Numbering</h1><p>Configure prefixes and sequential numbering for ERP documents.</p></div></div>
@if($errors->any())<section class="panel" style="color:#b42318">{{ $errors->first() }}</section>@endif
<div class="sequence-layout">
<section class="panel"><h2>Create Numbering Series</h2><p class="sequence-help">Example: PO-00001. Each issued number is reserved within a database transaction.</p>
@can('administration.update')
<form class="sequence-form" data-new-entry-form="1" method="POST" action="{{ route('admin.sequences.store') }}">@csrf
<label>Document Type<select name="document_type" id="document-type" class="enhanced-select" required><option value="">Select document</option>
@foreach($types as $type)
@if(!$sequences->contains('document_type',$type))
<option value="{{ $type }}">{{ ucwords(str_replace('_',' ',$type)) }}</option>
@endif
@endforeach
</select></label>
<label>Generated Prefix<input id="generated-prefix" readonly placeholder="Select document type"></label>
<label>Starting Number<input name="next_number" type="number" min="1" max="999999999" value="{{ old('next_number',1) }}" required></label>
<label>Minimum Digits<input name="padding" type="number" min="2" max="12" value="{{ old('padding',5) }}" required></label>
<button class="btn" type="submit"><i class="fa-solid fa-plus"></i> Create Series</button>
</form>
@endcan
</section>
<section class="panel"><h2>Configured Numbering Series ({{ $sequences->count() }})</h2><div class="table-wrap"><table><thead><tr><th>Document</th><th>Next Number Preview</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($sequences as $sequence)
<tr><td><strong>{{ ucwords(str_replace('_',' ',$sequence->document_type)) }}</strong></td><td class="sequence-code">{{ $sequence->prefix }}{{ str_pad((string)$sequence->next_number,$sequence->padding,'0',STR_PAD_LEFT) }}</td><td>{{ $sequence->is_active ? 'Active' : 'Disabled' }}</td><td>
@can('administration.update')<button type="button" class="sequence-edit" onclick="document.getElementById('sequence-{{ $sequence->id }}').showModal()"><i class="fa-solid fa-pen"></i> Edit</button>@endcan
</td></tr>
@empty
<tr><td colspan="4" class="empty">No numbering series configured.</td></tr>
@endforelse
</tbody></table></div></section></div>
@can('administration.update')
@foreach($sequences as $sequence)
<dialog id="sequence-{{ $sequence->id }}" style="border:1px solid #dae5f2;border-radius:12px;padding:24px;max-width:430px;width:90%;box-shadow:0 20px 60px #17365c33"><h2>{{ ucwords(str_replace('_',' ',$sequence->document_type)) }}</h2><p class="sequence-help">Next number cannot be reset from this page.</p>
<form class="sequence-form" method="POST" action="{{ route('admin.sequences.update',$sequence) }}">@csrf @method('PUT')
<label>Generated Prefix<input value="{{ $sequence->prefix }}" readonly></label>
<label>Minimum Digits<input name="padding" type="number" min="2" max="12" required value="{{ $sequence->padding }}"></label>
<label>Status<select name="is_active" class="enhanced-select"><option value="1" @selected($sequence->is_active)>Active</option><option value="0" @selected(!$sequence->is_active)>Disabled</option></select></label>
<div style="display:flex;gap:10px"><button class="btn" type="submit">Save Changes</button><button type="button" onclick="this.closest('dialog').close()">Cancel</button></div></form></dialog>
@endforeach
@endcan




<script>
document.addEventListener('DOMContentLoaded',function(){const prefixes=@json($prefixes),type=document.getElementById('document-type'),prefix=document.getElementById('generated-prefix');function sync(){prefix.value=prefixes[type.value]||'';}type.addEventListener('change',sync);sync();if(window.jQuery)jQuery(type).on('change',sync);});
</script>
@endsection