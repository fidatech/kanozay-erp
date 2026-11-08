@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1>Employee register</h1><p>Employee profile fields follow Kanozay’s supplied payroll workbook. Verified employment dates determine historical payroll eligibility; no tax or deduction rule is applied automatically.</p></div><a class="btn btn-light" href="{{ route('workspace.page', ['hr', 'departments']) }}">Departments</a>
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
  <label>Department<select name="department_id"><option value="">Unassigned</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->code }} — {{ $department->name }}</option>@endforeach</select></label>
  <label>Phone<input name="phone" value="{{ old('phone') }}" maxlength="80"></label>
  <label>National ID<input name="national_id" value="{{ old('national_id') }}" maxlength="100" autocomplete="off"></label>
  <label>Taxpayer ID<input name="taxpayer_id" value="{{ old('taxpayer_id') }}" maxlength="100" autocomplete="off"></label>
  <label>Employment start date (if verified)<input name="hired_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('hired_on') }}"></label>
  <label>Monthly salary<input name="monthly_salary" type="text" inputmode="decimal" value="{{ old('monthly_salary') }}" required></label>
  <label>Salary currency<select name="salary_currency_id" required><option value="">Select currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('salary_currency_id')==$currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
  <div><button class="btn" type="submit">Save employee</button></div>
 </form>
</div>
@endcan
@can('hr.import')
<div class="card" style="padding:18px;margin-bottom:16px">
 <h2>Import from Kanozay payroll workbook</h2>
 <p class="muted">Upload the original .xlsx file to validate the employee fields. A separate confirmation is required before records are saved. The source tax/transport formula is not imported or calculated.</p>
 <form method="POST" action="{{ route('hr.employees.import-preview') }}" enctype="multipart/form-data" class="filters">@csrf
  <input type="file" name="file" accept=".xlsx" required aria-label="Kanozay payroll workbook">
  <select name="salary_currency_id" required aria-label="Salary currency"><option value="">Select salary currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select>
  <button class="btn" type="submit">Preview workbook</button>
 </form>
</div>
@endcan
<div class="card" style="padding:20px">
 <div class="heading"><h2>Employees</h2><form method="GET" action="{{ route('workspace.page',['hr','employees']) }}" class="filters">
  <input name="search" value="{{ request('search') }}" placeholder="Employee number or position" aria-label="Search employees">
  <select name="status" aria-label="Employee status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
  <button class="btn" type="submit">Filter</button>
 </form></div>
 <div class="table-wrap"><table><thead><tr><th>Employee number</th><th>Name</th><th>Position</th><th>Department</th><th>Monthly salary</th><th>Employment dates</th><th>Status</th><th>Action</th></tr></thead><tbody>
 @forelse($employees as $employee)
  <tr><td>{{ $employee->employee_number }}</td><td>{{ $employee->full_name }}</td><td>{{ $employee->position ?: '—' }}</td><td>{{ $employee->department?->name ?? 'Unassigned' }}
@can('hr.update')<form method="POST" action="{{ route('hr.employees.department', $employee) }}" style="display:flex;gap:4px">@csrf @method('PATCH')<select name="department_id"><option value="">Unassigned</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected($employee->department_id == $department->id)>{{ $department->name }}</option>@endforeach</select><button class="btn btn-secondary" type="submit">Assign</button></form>@endcan</td><td><x-finance-amount :amount="$employee->monthly_salary" /> {{ $employee->salaryCurrency?->code }}</td>
<td>
@if($employee->hired_on)Hired: {{ $employee->hired_on->format('Y-m-d') }}
@else <span style="color:#986015">Hire date not verified</span>@endif
@if($employee->separated_on)<div>Separated: {{ $employee->separated_on->format('Y-m-d') }}</div>@endif
</td>
<td>{{ $employee->is_active ? 'Active' : 'Inactive' }}</td>
<td>
@can('hr.update')
@if(!$employee->separated_on)
<details><summary>Verify hire date</summary>
<form method="POST" action="{{ route('hr.employees.update',$employee) }}" style="display:grid;gap:7px;min-width:210px">
@csrf @method('PATCH')
<input type="date" name="hired_on" max="{{ now()->toDateString() }}" value="{{ $employee->hired_on?->format('Y-m-d') }}" required aria-label="Verified employment start">
<input name="evidence_reference" required minlength="3" maxlength="180" placeholder="Contract / personnel reference">
<input name="reason" required minlength="10" maxlength="1000" placeholder="Verified HR evidence / correction reason">
<button class="btn btn-secondary" type="submit">Save verified date</button>
</form></details>
@endif
<a href="{{ route('workspace.page',['hr','exits']) }}">Employee separation workflow</a>
@else — @endcan
</td></tr>
 @empty<tr><td colspan="8">No employee profiles match this filter.</td></tr>@endforelse
 </tbody></table></div>
 {{ $employees->links() }}
</div>
@endsection
