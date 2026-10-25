@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>User Management</h1><p>Manage employee accounts and access levels</p></div>@can('users.create')<button class="btn" type="button" data-modal-open="create-user"><i class="fa-solid fa-plus"></i> Add User</button>@endcan</div>
@if($errors->any())<div class="panel error" role="alert">{{ implode(' | ', $errors->all()) }}</div>@endif
<section class="panel"><h2>Employee Accounts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody>
@forelse($users as $user)
<tr><td><strong>{{ $user->name }}</strong></td><td>{{ $user->email }}</td><td><span class="status">{{ $user->roles->pluck('name')->join(', ') ?: 'Unassigned' }}</span></td><td>
@can('users.update')<button type="button" class="btn" data-modal-open="edit-user-{{ $user->id }}"><i class="fa-solid fa-pen"></i> Edit</button>@endcan
@can('users.delete')<form class="inline delete-user-form" method="POST" action="{{ route('admin.users.destroy',$user) }}">@csrf @method('DELETE')<button type="submit" class="btn danger-btn"><i class="fa-solid fa-trash"></i> Delete</button></form>@endcan
</td></tr>
@empty<tr><td colspan="4" class="empty"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></div><strong>No employee accounts yet</strong><p>Create a user account to grant access to the ERP.</p></div></td></tr>@endforelse
</tbody></table></div>{{ $users->onEachSide(1)->links('vendor.pagination.kanozay') }}</section>
@can('users.create')
<dialog class="k-modal" id="create-user" aria-labelledby="create-user-title"><form method="POST" action="{{ route('admin.users.store') }}" class="modal-form">@csrf
<div class="modal-head"><h2 id="create-user-title">Add Employee</h2><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<div class="modal-body"><label>Full Name<input name="name" value="{{ old('name') }}" required maxlength="255"></label><label>Email Address<input type="email" name="email" value="{{ old('email') }}" required></label><label>Password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Confirm Password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label><label>Role<select name="role" required>@foreach($roles as $role)<option value="{{ $role->name }}" @selected(old('role')===$role->name)>{{ $role->name }}</option>@endforeach</select></label></div>
<div class="modal-foot"><button type="button" class="btn btn-light" data-modal-close>Cancel</button><button class="btn" type="submit">Create Employee</button></div></form></dialog>
@endcan
@can('users.update')
@foreach($users as $user)
<dialog class="k-modal" id="edit-user-{{ $user->id }}" aria-labelledby="edit-title-{{ $user->id }}"><form method="POST" action="{{ route('admin.users.update',$user) }}" class="modal-form">@csrf @method('PUT')
<div class="modal-head"><h2 id="edit-title-{{ $user->id }}">Edit Employee</h2><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<div class="modal-body"><label>Full Name<input name="name" value="{{ $user->name }}" required maxlength="255"></label><label>Email Address<input type="email" name="email" value="{{ $user->email }}" required></label><label>New Password <small>(leave blank to keep current)</small><input type="password" name="password" minlength="12" autocomplete="new-password"></label><label>Confirm New Password<input type="password" name="password_confirmation" autocomplete="new-password"></label><label>Role<select name="role" required>@foreach($roles as $role)<option value="{{ $role->name }}" @selected($user->hasRole($role->name))>{{ $role->name }}</option>@endforeach</select></label></div>
<div class="modal-foot"><button type="button" class="btn btn-light" data-modal-close>Cancel</button><button class="btn" type="submit">Save Changes</button></div></form></dialog>
@endforeach
@endcan
@endsection
@push('scripts')
<script>
document.querySelectorAll('.delete-user-form').forEach(form => form.addEventListener('submit', async e => {
 e.preventDefault();
 if (typeof Swal === 'undefined') { if(window.confirm('Delete this employee account?')) form.submit(); return; }
 const result = await Swal.fire({title:'Delete employee?',text:'This account will lose access to Kanozay ERP.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d92d20',confirmButtonText:'Yes, delete',cancelButtonText:'Cancel',reverseButtons:true});
 if(result.isConfirmed) form.submit();
}));
</script>
@endpush