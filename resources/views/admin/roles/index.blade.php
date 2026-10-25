@extends('layouts.kanozay')
@section('content')
<style>
.role-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}.role-search{width:min(340px,100%);padding:11px 13px!important}.role-table{width:100%;border-collapse:collapse}.role-table th{text-transform:uppercase;letter-spacing:.05em;font-size:10px;color:#6176a1;padding:13px}.role-table td{padding:17px 13px;vertical-align:middle}.role-name{display:flex;gap:12px;align-items:center;font-size:13px;font-weight:700}.role-avatar{height:40px;width:40px;border-radius:10px;background:#eaf3ff;display:grid;place-items:center;color:#1565e0;font-size:17px}.role-perms{display:flex;gap:5px;flex-wrap:wrap;max-width:800px}.permission-chip{font-size:10px;color:#28538b;background:#eef5ff;border:1px solid #dceafd;border-radius:5px;padding:5px 8px;white-space:nowrap}.permission-more{color:#1565e0;background:#f0f5ff;border-radius:5px;padding:5px 8px;font-size:10px}.role-actions{display:flex;gap:7px;align-items:center}.role-actions button{white-space:nowrap}.role-actions .btn{padding:8px 11px}.role-stats{display:flex;gap:12px;margin-bottom:18px}.role-stat{padding:16px 20px;min-width:160px}.role-stat b{font-size:23px;display:block;margin-top:8px}.permission-group{border:1px solid #e5edf7;border-radius:8px;margin:0 0 12px;overflow:hidden}.permission-group-head{display:flex;align-items:center;justify-content:space-between;background:#f4f8ff;padding:11px 14px;font-weight:700}.permission-options{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:13px}.permission-options label{display:flex;align-items:center;gap:7px;font-weight:400;font-size:12px}.permission-options input{min-height:0!important;width:14px!important;height:14px!important;flex:0 0 14px}.permission-group-head input.group-select-all{appearance:auto!important;width:17px!important;height:17px!important;min-height:0!important;margin:0 6px 0 0;vertical-align:middle;cursor:pointer}.permission-group-head label{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:500;cursor:pointer}.permission-dialog{width:min(820px,calc(100vw - 30px))!important}.permission-dialog .modal-body{max-height:60vh}.permission-dialog .modal-body>label{display:grid}.role-empty{color:#8493ad;font-style:italic}@media(max-width:750px){.role-stats{flex-wrap:wrap}.permission-options{grid-template-columns:repeat(2,1fr)}.role-table td{min-width:130px}}
</style>
<div class="heading"><div><h1>Roles & Permissions</h1><p>Control who can access each area of Kanozay ERP.</p></div>@can('roles.create')<button type="button" class="btn" data-modal-open="create-role"><i class="fa-solid fa-plus"></i> Create Role</button>@endcan</div>
@if($errors->any())<div class="panel error" role="alert">{{ implode(' | ', $errors->all()) }}</div>@endif
<div class="role-stats"><div class="panel role-stat"><span class="muted">Total Roles</span><b>{{ $roles->count() }}</b></div><div class="panel role-stat"><span class="muted">Available Permissions</span><b>{{ $permissions->count() }}</b></div></div>
<section class="panel"><div class="role-toolbar"><h2 style="margin:0">Access Roles</h2><input id="role-search" class="role-search" type="search" placeholder="Search roles or permissions..." aria-label="Search roles and permissions"></div>
<div class="table-wrap"><table class="role-table"><thead><tr><th style="width:24%">Role</th><th>Permissions assigned to role</th><th style="width:14%">Actions</th></tr></thead><tbody>
@foreach($roles as $role)
<tr class="role-row"><td><div class="role-name"><span class="role-avatar"><i class="fa-solid {{ $role->name === 'Super Administrator' ? 'fa-shield-halved' : 'fa-user-shield' }}"></i></span><div><span>{{ $role->name }}</span><div class="muted" style="font-size:11px;font-weight:400;margin-top:5px">{{ $role->permissions->count() }} permissions</div></div></div></td>
<td><div class="role-perms">@forelse($role->permissions->sortBy('name') as $permission)@if($loop->index < 10)<span class="permission-chip">{{ $permission->name }}</span>@endif @if($loop->index === 10)<span class="permission-more">+{{ $role->permissions->count()-10 }} more</span>@endif @empty<span class="role-empty">No permissions assigned</span>@endforelse</div></td>
<td>@if($role->name === 'Super Administrator')<span class="permission-chip"><i class="fa-solid fa-lock"></i> Protected</span>@else<div class="role-actions">@can('roles.update')<button class="btn" type="button" data-modal-open="edit-role-{{ $role->id }}" aria-label="Edit {{ $role->name }}"><i class="fa-solid fa-pen"></i> Edit</button>@endcan @can('roles.delete')<form class="inline delete-role-form" method="POST" action="{{ route('admin.roles.destroy',$role) }}">@csrf @method('DELETE')<button type="submit" class="btn danger-btn" aria-label="Delete {{ $role->name }}"><i class="fa-solid fa-trash"></i></button></form>@endcan</div>@endif</td></tr>
@endforeach
</tbody></table></div><p id="no-role-results" class="empty" hidden>No matching roles or permissions.</p></section>
@can('roles.create')
<dialog class="k-modal permission-dialog" id="create-role" aria-labelledby="create-role-title"><form method="POST" action="{{ route('admin.roles.store') }}" class="modal-form">@csrf
<div class="modal-head"><h2 id="create-role-title">Create New Role</h2><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div><div class="modal-body"><label>Role Name<input type="text" name="name" placeholder="e.g. Purchasing Officer" maxlength="125" required></label><div class="muted">Select the permissions this role should have.</div>
@foreach($permissions->groupBy(fn($permission) => explode('.', $permission->name)[0]) as $module => $group)
<div class="permission-group"><div class="permission-group-head"><span>{{ ucfirst($module) }}</span><label><input type="checkbox" class="group-select-all"> Select all</label></div><div class="permission-options">@foreach($group as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->name }}"> {{ ucfirst(explode('.', $permission->name)[1] ?? $permission->name) }}</label>@endforeach</div></div>
@endforeach</div><div class="modal-foot"><button type="button" class="btn btn-light" data-modal-close>Cancel</button><button type="submit" class="btn">Create Role</button></div></form></dialog>
@endcan
@can('roles.update')
@foreach($roles as $role)
@if($role->name !== 'Super Administrator')
<dialog class="k-modal permission-dialog" id="edit-role-{{ $role->id }}" aria-labelledby="edit-role-title-{{ $role->id }}"><form method="POST" action="{{ route('admin.roles.update',$role) }}" class="modal-form">@csrf @method('PUT')
<div class="modal-head"><h2 id="edit-role-title-{{ $role->id }}">Edit Role — {{ $role->name }}</h2><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div><div class="modal-body"><label>Role Name<input type="text" name="name" value="{{ $role->name }}" maxlength="125" required></label><div class="muted">Adjust permissions for employees assigned to this role.</div>
@foreach($permissions->groupBy(fn($permission) => explode('.', $permission->name)[0]) as $module => $group)
<div class="permission-group"><div class="permission-group-head"><span>{{ ucfirst($module) }}</span><label><input type="checkbox" class="group-select-all"> Select all</label></div><div class="permission-options">@foreach($group as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($role->hasPermissionTo($permission))> {{ ucfirst(explode('.', $permission->name)[1] ?? $permission->name) }}</label>@endforeach</div></div>
@endforeach</div><div class="modal-foot"><button type="button" class="btn btn-light" data-modal-close>Cancel</button><button type="submit" class="btn">Save Changes</button></div></form></dialog>
@endif
@endforeach
@endcan
@endsection
@push('scripts')
<script>
document.querySelectorAll('.permission-group').forEach(group=>{
 const all=group.querySelector('.group-select-all'), items=[...group.querySelectorAll('input[name="permissions[]"]')];
 const sync=()=>{all.checked=items.length>0&&items.every(input=>input.checked);all.indeterminate=items.some(input=>input.checked)&&!all.checked;};
 all.addEventListener('change',()=>{items.forEach(input=>input.checked=all.checked);sync();});
 items.forEach(input=>input.addEventListener('change',sync));sync();
});
document.getElementById('role-search')?.addEventListener('input',event=>{
 const query=event.target.value.trim().toLowerCase();let visible=0;
 document.querySelectorAll('.role-row').forEach(row=>{const match=row.textContent.toLowerCase().includes(query);row.hidden=!match;if(match)visible++;});
 document.getElementById('no-role-results').hidden=visible!==0;
});
document.querySelectorAll('.delete-role-form').forEach(form=>form.addEventListener('submit',async event=>{
 event.preventDefault();
 if(typeof Swal==='undefined'){if(confirm('Delete this role?'))form.submit();return;}
 const result=await Swal.fire({title:'Delete role?',text:'Roles assigned to employees cannot be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d92d20',confirmButtonText:'Yes, delete',reverseButtons:true});
 if(result.isConfirmed)form.submit();
}));
</script>
@endpush