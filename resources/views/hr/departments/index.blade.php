@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Departments</h1><p>Manage department codes and employee assignments without changing payroll amounts.</p></div><a class="btn btn-light" href="{{ route('workspace.page', ['hr', 'employees']) }}">Employees</a></div>
@if(session('success'))<p role="status" class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="notice notice-warning">{{ $errors->first() }}</p>@endif
@can('hr.create')
<div class="card" style="padding:20px;margin-bottom:16px"><h2>Add department</h2><form method="POST" action="{{ route('hr.departments.store') }}" class="form-grid">@csrf<label>Code<input name="code" value="{{ old('code') }}" maxlength="32" required></label><label>Name<input name="name" value="{{ old('name') }}" maxlength="180" required></label><div><button class="btn" type="submit">Save department</button></div></form></div>
@endcan
<div class="card" style="padding:20px"><h2>Department register</h2><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Employees</th><th>Status</th><th>Update</th></tr></thead><tbody>
@forelse($departments as $department)
<tr><td>{{ $department->code }}</td><td>{{ $department->name }}</td><td>{{ $department->employees_count }}</td><td>{{ $department->is_active ? 'Active' : 'Inactive' }}</td><td>
@can('hr.update')
<form method="POST" action="{{ route('hr.departments.update', $department) }}" style="display:flex;gap:6px;flex-wrap:wrap">@csrf @method('PATCH')<input name="name" value="{{ $department->name }}" maxlength="180" required><select name="is_active"><option value="1" @selected($department->is_active)>Active</option><option value="0" @selected(! $department->is_active)>Inactive</option></select><button class="btn btn-secondary" type="submit">Save</button></form>
@else — @endcan
</td></tr>
@empty
<tr><td colspan="5" class="empty">No departments recorded.</td></tr>
@endforelse
</tbody></table></div>{{ $departments->links() }}</div>
@endsection
