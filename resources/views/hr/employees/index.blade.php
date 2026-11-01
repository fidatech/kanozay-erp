@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1>Employee register</h1><p>Employee profile fields follow Kanozay’s supplied payroll workbook. No tax or deduction rule is applied here.</p></div>
</div>
@if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
@can('hr.create')
<div class="card" style="padding:20px;margin-bottom:16px">
 <h2>Add employee</h2>
 <form method="POST" action="{{ route('hr.employees.store') }}" class="form-grid">
  @csrf
  <label>Employee number<input name="employee_number" value="{{ old('employee_number') }}" maxlength="32" required></label>
  <label>Full name<input name="full_name" value="{{ old('full_name') }}" maxlength="180" required></label>
  <label>Father’s name<input name="father_name" value="{{ old('father_name') }}" maxlength="180"></label>
  <label>Gender<input name="gender" value="{{ old('gender') }}" maxlength="32"></label>
  <label>Position<input name="position" value="{{ old('position') }}" maxlength="180"></label>
  <label>Phone<input name="phone" value="{{ old('phone') }}" maxlength="80"></label>
  <label>National ID<input name="national_id" value="{{ old('national_id') }}" maxlength="100" autocomplete="off"></label>
  <label>Taxpayer ID<input name="taxpayer_id" value="{{ old('taxpayer_id') }}" maxlength="100" autocomplete="off"></label>
  <label>Monthly salary<input name="monthly_salary" type="text" inputmode="decimal" value="{{ old('monthly_salary') }}" required></label>
  <div><button class="btn" type="submit">Save employee</button></div>
 </form>
</div>
@endcan
<div class="card" style="padding:20px">
 <div class="heading"><h2>Employees</h2><form method="GET" action="{{ route('workspace.page',['hr','employees']) }}" class="filters">
  <input name="search" value="{{ request('search') }}" placeholder="Employee number or position" aria-label="Search employees">
  <select name="status" aria-label="Employee status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
  <button class="btn" type="submit">Filter</button>
 </form></div>
 <div class="table-wrap"><table><thead><tr><th>Employee number</th><th>Name</th><th>Position</th><th>Monthly salary</th><th>Status</th><th>Action</th></tr></thead><tbody>
 @forelse($employees as $employee)
  <tr><td>{{ $employee->employee_number }}</td><td>{{ $employee->full_name }}</td><td>{{ $employee->position ?: '—' }}</td><td><x-finance-amount :amount="$employee->monthly_salary" /></td><td>{{ $employee->is_active ? 'Active' : 'Inactive' }}</td>
   <td>@can('hr.update')<form method="POST" action="{{ route('hr.employees.update',$employee) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $employee->is_active ? '0' : '1' }}"><button class="btn btn-secondary" type="submit">{{ $employee->is_active ? 'Deactivate' : 'Activate' }}</button></form>@else — @endcan</td></tr>
 @empty<tr><td colspan="6">No employee profiles match this filter.</td></tr>@endforelse
 </tbody></table></div>
 {{ $employees->links() }}
</div>
@endsection
