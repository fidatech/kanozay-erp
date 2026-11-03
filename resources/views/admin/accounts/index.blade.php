@extends('layouts.kanozay')
@section('content')
<style>.coa-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:18px}.coa-form{display:grid!important;gap:13px!important}.coa-form label{display:grid;gap:7px;color:#2a456d;font-size:12px;font-weight:700}.coa-form input,.coa-form select,.coa-form textarea{min-height:42px;width:100%;border:1px solid #d8e3f1;border-radius:7px;padding:10px 12px;color:#253e64}.coa-form textarea{min-height:70px}.coa-tag{border-radius:20px;padding:5px 9px;background:#e9f4ff;color:#1765b0;font-weight:700;font-size:11px}.coa-muted{font-size:12px;color:#7585a0;line-height:1.6}.coa-action{border:1px solid #d8e3f1;border-radius:6px;background:white;color:#1d66ad;padding:8px 12px;cursor:pointer}.coa-code{font-weight:800;color:#185fa6;font-family:monospace}@media(max-width:960px){.coa-grid{grid-template-columns:1fr}}
.coa-filter-form{display:flex;align-items:center;gap:10px;margin:0;min-width:195px}
.coa-filter-label{white-space:nowrap;font-size:11px;font-weight:700;color:#617897}
.coa-filter-form .select2-container{width:170px!important;min-width:150px}
.coa-filter-form .select2-selection--single{min-height:38px!important;height:38px!important}
@media(max-width:650px){.coa-filter-form{width:100%;justify-content:flex-end}.coa-filter-label{display:none}}

.coa-import-panel{margin-bottom:16px;border:1px solid #d6e5f5;background:#f8fbff;border-radius:13px;padding:18px}.coa-import-header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.coa-import-header h2{margin:0 0 5px}.coa-import-header p{margin:0;color:#6e8199;font-size:12px}.coa-import-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.coa-import-form{display:flex;align-items:end;gap:9px;flex-wrap:wrap;margin:15px 0 0}.coa-import-form input[type=file]{padding:8px;background:#fff;border:1px solid #d7e4f1;border-radius:8px;max-width:320px}.coa-legacy-details{margin-top:14px;border-top:1px solid #dce7f4;padding-top:12px}.coa-legacy-details summary{cursor:pointer;color:#355c85;font-weight:700;font-size:12px}.coa-legacy-details p{margin:9px 0;color:#70819a}.coa-legacy-details form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.coa-register-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}.coa-register-header h2{margin:0}.coa-search-form{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.coa-search-form input{height:39px;min-width:220px;max-width:320px;padding:9px 12px;border:1px solid #d5e2ef;border-radius:8px}.coa-search-form select{height:39px;min-width:130px;border:1px solid #d5e2ef;border-radius:8px;padding:7px 10px}.coa-search-form button{min-height:39px}.coa-row-actions{display:flex;gap:7px;align-items:center}.coa-row-actions form{margin:0!important}.coa-delete{border:1px solid #f2cccc;background:#fff6f5;color:#ab3232;border-radius:7px;padding:8px 10px;cursor:pointer}.coa-delete:hover{background:#ffebe8}.coa-edit-dialog{border:1px solid #d9e4f1;border-radius:16px;padding:0;width:min(570px,94vw);max-height:88vh;box-shadow:0 25px 70px #17396535}.coa-edit-dialog::backdrop{background:#0a1d3f70}.coa-edit-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e3eaf3}.coa-edit-head h2{margin:0;font-size:17px}.coa-edit-head button{border:none;background:transparent;font-size:23px;color:#607d9b;cursor:pointer}.coa-edit-body{padding:20px;max-height:67vh;overflow:auto}.coa-edit-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;padding-top:12px;border-top:1px solid #e5edf5}.coa-edit-actions button{min-height:40px}.coa-form label{min-width:0}.coa-form select{max-width:100%}@media(max-width:700px){.coa-search-form{width:100%}.coa-search-form input{flex:1;min-width:150px}.coa-import-form input[type=file]{width:100%}.coa-edit-body{padding:14px}}

/* Keep all account filters on a single desktop row. */
.coa-register-header{display:block!important;margin-bottom:14px!important}
.coa-register-header>h2{margin:0 0 12px!important}
.coa-register-header .coa-search-form{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(130px,170px) auto auto!important;align-items:center!important;gap:9px!important;width:100%!important;min-width:0!important;max-width:100%!important;flex-wrap:nowrap!important;margin:0!important}
.coa-register-header .coa-search-form>input,.coa-register-header .coa-search-form>select{width:100%!important;min-width:0!important;max-width:none!important;height:40px!important;min-height:40px!important;box-sizing:border-box!important}
.coa-register-header .coa-search-form>.btn,.coa-register-header .coa-search-form>.coa-action{min-height:40px!important;display:inline-flex!important;align-items:center;justify-content:center;white-space:nowrap!important;padding:8px 15px!important;text-decoration:none!important}
@media(max-width:720px){.coa-register-header .coa-search-form{grid-template-columns:minmax(0,1fr) minmax(110px,160px) auto auto!important;gap:6px!important}.coa-register-header .coa-search-form>.btn,.coa-register-header .coa-search-form>.coa-action{padding:8px 10px!important;font-size:11px!important}}
@media(max-width:500px){.coa-register-header .coa-search-form{display:flex!important;flex-wrap:wrap!important}.coa-register-header .coa-search-form>input{flex:1 1 100%!important}.coa-register-header .coa-search-form>select{flex:1 1 120px!important}.coa-register-header .coa-search-form>.btn,.coa-register-header .coa-search-form>.coa-action{flex:0 0 auto!important}}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-sitemap" style="color:#2b8d70"></i> Chart of Accounts</h1><p>Configure the financial account hierarchy for Kanozay Foods.</p></div></div>
<section class="coa-import-panel">
<div class="coa-import-header"><div><h2><i class="fa-solid fa-file-excel"></i> Chart of Accounts — Excel import</h2><p>Download the standard workbook, fill your accounts, then upload it. Existing accounts and accounting entries are never overwritten.</p></div><a class="btn" href="{{ route('admin.accounts.template') }}"><i class="fa-solid fa-download"></i> Download Excel Sample</a></div>
@can('administration.update')
<form method="POST" class="coa-import-form" action="{{ route('admin.accounts.import') }}" enctype="multipart/form-data">@csrf<label>Completed COA workbook (.xlsx)<input type="file" name="file" accept=".xlsx" required></label><button type="submit" class="btn"><i class="fa-solid fa-file-import"></i> Import Accounts</button></form>
<details class="coa-legacy-details"><summary>Review old Kanozay accounting ledgers (.xls / .xlsx) — review only</summary><p>This separate legacy review identifies existing worksheets. It does not import accounts or balances.</p><form method="POST" action="{{ route('admin.accounts.workbook-review') }}" enctype="multipart/form-data">@csrf<label>Legacy accounts workbook<input type="file" name="file" accept=".xls,.xlsx" required></label><button type="submit" class="btn">Review legacy workbook</button></form></details>
@endcan
</section>
@if($errors->any())<section class="panel" style="color:#b42318">{{ $errors->first() }}</section>@endif
<div class="coa-grid"><section class="panel"><h2>Create Account</h2><p class="coa-muted">Create group accounts first, then add posting accounts beneath them. No opening balances are generated automatically.</p>
@can('administration.update')<form class="coa-form" data-new-entry-form="1" method="POST" action="{{ route('admin.accounts.store') }}">@csrf
<label>Account Code<input name="code" maxlength="24" pattern="[A-Za-z0-9.-]+" placeholder="e.g. 1100" required value="{{ old('code') }}"></label>
<label>Account Name<input name="name" maxlength="160" placeholder="e.g. Cash on Hand" required value="{{ old('name') }}"></label>
<label>Account Type<select name="type" class="coa-select" required>@foreach($types as $type)<option value="{{ $type }}" @selected(old('type')===$type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
<label>Parent Group<select name="parent_id" class="coa-select"><option value="">Top-level account</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('parent_id')==$group->id)>{{ $group->code }} — {{ $group->name }} ({{ ucfirst($group->type) }})</option>@endforeach</select></label>
<label>Account Kind<select name="is_group" class="coa-select"><option value="0">Posting Account</option><option value="1">Group / Header Account</option></select></label>
<label>Status<select name="is_active" class="coa-select"><option value="1">Active</option><option value="0">Inactive</option></select></label>
<label>Description<textarea name="description" maxlength="500">{{ old('description') }}</textarea></label><button class="btn" type="submit"><i class="fa-solid fa-plus"></i> Add Account</button></form>@endcan</section>
<section class="panel"><div class="coa-register-header"><h2>Account Directory</h2><form method="GET" action="{{ route('admin.accounts.index') }}" class="coa-search-form"><input type="search" name="q" value="{{ request('q') }}" placeholder="Search account code or name" aria-label="Search chart of accounts"><select name="type" aria-label="Account type"><option value="">All Types</option>@foreach($types as $type)<option value="{{ $type }}" @selected(request('type')===$type)>{{ ucfirst($type) }}</option>@endforeach</select><button type="submit" class="btn">Search</button>@if(request()->filled('q')||request()->filled('type'))<a href="{{ route('admin.accounts.index') }}" class="coa-action">Clear</a>@endif</form></div>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Account</th><th>Type</th><th>Parent</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($accounts as $account)<tr><td class="coa-code">{{ $account->code }}</td><td><strong>{{ $account->name }}</strong> @if($account->is_group)<span class="coa-tag">Group</span>@endif<br><small class="coa-muted">{{ $account->description }}</small></td><td>{{ ucfirst($account->type) }}</td><td>{{ $account->parent?->code ?? '—' }}</td><td>{{ $account->is_active ? 'Active' : 'Inactive' }}</td><td>@can('administration.update')<div class="coa-row-actions"><button class="coa-action" type="button" onclick="document.getElementById('account-{{ $account->id }}').showModal()"><i class="fa-solid fa-pen"></i> Edit</button>@if(in_array($account->id,$deletableIds,true))<form class="coa-delete-form" method="POST" action="{{ route('admin.accounts.destroy',$account) }}" data-account-code="{{ $account->code }}">@csrf @method('DELETE')<button class="coa-delete" type="submit" title="Delete unused account"><i class="fa-solid fa-trash"></i> Delete</button></form>@else<span class="coa-muted" title="Account is used or has child accounts">Protected</span>@endif</div>@endcan</td></tr>@empty<tr><td colspan="6" class="empty"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-sitemap" aria-hidden="true"></i></div><strong>No ledger accounts yet</strong><p>Create your first account to build the Chart of Accounts.</p></div></td></tr>@endforelse
</tbody></table></div>{{ $accounts->onEachSide(1)->links('vendor.pagination.kanozay') }}</section></div>
@can('administration.update')
@foreach($accounts as $account)<dialog id="account-{{ $account->id }}" class="coa-edit-dialog"><div class="coa-edit-head"><h2>Edit Account — {{ $account->code }}</h2><button type="button" aria-label="Close" onclick="this.closest('dialog').close()">×</button></div><div class="coa-edit-body"><form class="coa-form" method="POST" action="{{ route('admin.accounts.update',$account) }}">@csrf @method('PUT')<label>Code<input name="code" value="{{ $account->code }}" maxlength="24" required></label><label>Name<input name="name" value="{{ $account->name }}" required maxlength="160"></label><label>Type<select name="type">@foreach($types as $type)<option value="{{ $type }}" @selected($account->type===$type)>{{ ucfirst($type) }}</option>@endforeach</select></label><label>Parent Group<select name="parent_id"><option value="">Top-level</option>@foreach($groups as $group)@if($group->id!==$account->id)<option value="{{ $group->id }}" @selected($account->parent_id===$group->id)>{{ $group->code }} — {{ $group->name }}</option>@endif @endforeach</select></label><label>Kind<select name="is_group"><option value="0" @selected(!$account->is_group)>Posting</option><option value="1" @selected($account->is_group)>Group</option></select></label><label>Status<select name="is_active"><option value="1" @selected($account->is_active)>Active</option><option value="0" @selected(!$account->is_active)>Inactive</option></select></label><label>Description<textarea name="description" maxlength="500">{{ $account->description }}</textarea></label><div class="coa-edit-actions"><button type="button" class="coa-action" onclick="this.closest('dialog').close()">Cancel</button><button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button></div></form></div></dialog>@endforeach
@endcan



<script>
document.addEventListener('DOMContentLoaded', () => {
 document.querySelectorAll('.coa-delete-form').forEach(form => {
  form.addEventListener('submit', async event => {
   event.preventDefault();
   const code=form.dataset.accountCode||'this account';
   let confirmed=false;
   if (window.Swal) {
    const result=await window.Swal.fire({
     title:'Delete account?',
     text:`Account ${code} will be permanently deleted if it has no entries or child accounts.`,
     icon:'warning',showCancelButton:true,confirmButtonText:'Yes, delete',cancelButtonText:'Cancel',
     confirmButtonColor:'#c0392b',reverseButtons:true,focusCancel:true
    });
    confirmed=result.isConfirmed;
   } else {
    confirmed=window.confirm(`Delete account ${code}? This action cannot be undone.`);
   }
   if(!confirmed)return;
   form.querySelector('button[type="submit"]')?.setAttribute('disabled','disabled');
   HTMLFormElement.prototype.submit.call(form);
  });
 });
});
</script>
@endsection
