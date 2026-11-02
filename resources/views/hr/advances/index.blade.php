@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Salary advances</h1><p>Each issue creates a cash outflow and employee advance receivable. No automatic payroll deduction or repayment policy is assumed.</p></div><a class="btn btn-light" href="{{ route('workspace.page', ['hr', 'payroll']) }}">Payroll</a></div>
@if(session('success'))<p role="status" class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="notice notice-warning">{{ $errors->first() }}</p>@endif
@if(auth()->user()->can('hr.update') && auth()->user()->can('finance.update'))
<div class="card" style="padding:20px;margin-bottom:16px"><h2>Issue an advance</h2><form method="POST" action="{{ route('hr.advances.store') }}" class="form-grid">
@csrf
<input type="hidden" name="submission_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Employee<select name="employee_profile_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(old('employee_profile_id') == $employee->id)>{{ $employee->employee_number }} — {{ $employee->full_name }}</option>@endforeach</select></label>
<label>Pay from<select name="source_account_id" required><option value="">Select mapped payment account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('source_account_id') == $account->id)>{{ $account->name }} · {{ $account->currency->code }}</option>@endforeach</select></label>
<label>Advance receivable ledger<select name="receivable_ledger_id" required><option value="">Select employee receivable account</option>@foreach($receivables as $ledger)<option value="{{ $ledger->id }}" @selected(old('receivable_ledger_id') == $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></label>
<label>Issue date<input type="date" name="issued_on" value="{{ old('issued_on', now()->toDateString()) }}" required></label>
<label>Amount<input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></label>
<label>Reason<input name="reason" value="{{ old('reason') }}" minlength="5" maxlength="500" required></label>
<div><button class="btn" type="submit">Pay and post advance</button></div></form></div>
@endif
<div class="card" style="padding:20px"><h2>Posted advances</h2><div class="table-wrap"><table><thead><tr><th>Issue date</th><th>Employee</th><th>Amount</th><th>Reason</th><th>Journal</th></tr></thead><tbody>
@forelse($advances as $advance)
<tr><td>{{ $advance->issued_on->toDateString() }}</td><td>{{ $advance->employee->employee_number }} — {{ $advance->employee->full_name }}</td><td>{{ $advance->amount }} {{ $advance->currency->code }}</td><td>{{ $advance->reason }}</td><td>{{ $advance->transaction?->journal?->number }}</td></tr>
@empty
<tr><td colspan="5" class="empty">No advances posted.</td></tr>
@endforelse
</tbody></table></div>{{ $advances->links() }}</div>
@endsection
