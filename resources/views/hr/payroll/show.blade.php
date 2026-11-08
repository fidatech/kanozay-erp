@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Payroll: {{ $period->period_start->format('Y-m') }} · {{ $period->currency->code }}</h1><p>Status: {{ ucfirst($period->status) }}. Salary snapshots are retained after employee profile changes.</p></div><div class="actions"><a class="btn btn-light" href="{{ route('workspace.page',['hr','attendance','month'=>$period->period_start->format('Y-m')]) }}">Attendance</a><a class="btn btn-light" href="{{ route('workspace.page',['hr','payroll']) }}">All periods</a></div></div>
@if(session('success'))<p role="status" class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="notice notice-warning">{{ $errors->first() }}</p>@endif
<p><a class="btn btn-light" href="{{ route('hr.payroll.tax-policies.index',['period_id'=>$period->id]) }}">Reviewed payroll tax policies and withholding preview</a></p>
<div class="card" style="padding:20px;margin-bottom:16px"><h2>Employee amounts</h2><p>Amounts shown are in {{ $period->currency->code }}. Attendance and approved leave are reviewed evidence, not automatic salary deductions. Every employee requires an evidence reference and documented review before the payroll accrual can be approved.</p>
@if($period->status==='draft')
@php($reviewCounts=collect($reviewStates)->countBy('status'))
<p style="padding:11px;border:1px solid #dce7f0;border-radius:8px"><strong>Evidence review:</strong>
{{ $reviewCounts['reviewed']??0 }} current,
{{ $reviewCounts['pending']??0 }} pending,
{{ $reviewCounts['stale']??0 }} stale.
Changes to pay amounts, components, leave, attendance or employment dates invalidate the prior review.</p>
@endif<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Attendance recorded</th><th>Salary snapshot</th><th>Approved payable</th><th>Reason</th><th>Payroll evidence review</th><th>Payment</th></tr></thead><tbody>
@foreach($period->lines as $line)<tr><td>{{ $line->employee->employee_number }}<br><small>{{ $line->employee->full_name }}</small>
@if(!$line->employee->hired_on)<br><small style="color:#945e1c">Hire date not verified: confirm eligibility manually</small>@endif
@if($line->employee->separated_on)<br><small>Separated {{ $line->employee->separated_on->format('Y-m-d') }}</small>@endif
</td><td>@php
    $counts = $attendance[$line->employee_profile_id] ?? [];
    $leaves = $approvedLeave->get($line->employee_profile_id, collect());
    $hasPayable = \Brick\Math\BigDecimal::of((string) $line->payable_amount)->isGreaterThan(0);
@endphp
@foreach($counts as $status => $count){{ ucfirst($status) }}: {{ $count }}<br>@endforeach
@if(empty($counts))
No records
@endif
@foreach($leaves as $leave)
<br>Approved leave: {{ $leave->starts_on->toDateString() }} to {{ $leave->ends_on->toDateString() }}
@endforeach
</td><td>{{ $line->salary_snapshot }}</td><td>@if($period->status === 'draft' && auth()->user()->can('hr.update'))<form method="POST" action="{{ route('hr.payroll.lines.update',[$period,$line]) }}" style="display:flex;gap:6px;flex-wrap:wrap">@csrf @method('PATCH')<input name="payable_amount" value="{{ $line->payable_amount }}" inputmode="decimal" required style="width:130px"><input name="adjustment_reason" value="{{ $line->adjustment_reason }}" maxlength="500" placeholder="Reason if changed" style="width:180px"><button class="btn btn-secondary" type="submit">Save</button></form>@else {{ $line->payable_amount }} @endif</td><td>
@foreach($line->components as $component)
<div><strong>{{ ucfirst($component->kind) }}</strong> · {{ $component->label }}:
{{ $component->kind==='deduction'?'-':'+' }}{{ $component->amount }}</div>
@endforeach
{{ $line->adjustment_reason??'—' }}
@if($period->status==='draft' && auth()->user()->can('hr.update') && auth()->user()->can('finance.update'))
<details style="margin:8px 0"><summary>Add reviewed payroll component</summary>
<form method="POST" action="{{ route('hr.payroll.components.store',[$period,$line]) }}" style="display:grid;gap:7px">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<select name="kind"><option value="allowance">Allowance / earning</option><option value="deduction">Deduction / withholding</option></select>
<input name="label" required minlength="2" maxlength="120" placeholder="Component description">
<input name="amount" required inputmode="decimal" placeholder="Amount">
<select name="ledger_account_id" required><option value="">Expense or liability GL account</option>
@foreach($expenses as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
@foreach($payables as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
</select>
<input name="approval_reference" required minlength="3" maxlength="180" placeholder="Approval/policy reference">
<input name="reason" required minlength="8" maxlength="2500" placeholder="Reviewed reason">
<button type="submit" class="btn btn-secondary">Add reviewed component</button>
</form></details>
@endif
</td><td>
@php($review=$reviewStates[$line->id]??['status'=>'pending'])
@if($period->status==='draft')
<strong style="color:{{ $review['status']==='reviewed'?'#16764b':($review['status']==='stale'?'#a15b1b':'#a12e2e') }}">{{ ucwords($review['status']) }}</strong>
@if(!empty($review['reference']))<div class="muted">{{ $review['reference'] }}</div>@endif
@can('hr.update')
@if(auth()->user()->can('finance.update'))
<details style="margin-top:8px"><summary>{{ $review['status']==='reviewed'?'Review again':'Review employee evidence' }}</summary>
<form action="{{ route('hr.payroll.lines.review',[$period,$line]) }}" method="POST" style="display:grid;gap:6px;min-width:210px">
@csrf
<input name="reference" minlength="3" maxlength="180" required placeholder="Attendance sheet / approval reference">
<textarea name="review_notes" minlength="15" maxlength="2000" required rows="3" placeholder="Review salary, recorded attendance, absences, approved leave and any documented adjustments"></textarea>
<label style="font-size:12px"><input type="checkbox" name="confirm_evidence" value="1" required> I reviewed the available evidence and any gaps.</label>
<button class="btn btn-secondary" type="submit">Save evidence review</button>
</form>
</details>
@endif
@endcan
@else<span class="muted">Review frozen on approval</span>@endif
</td><td>
@if($line->payment_transaction_id)Paid · {{ $line->payment?->journal?->number ?? 'Posted' }}
<a href="{{ route('hr.payroll.lines.payslip',[$period,$line]) }}">Payslip</a>
@elseif($period->status === 'approved' && $hasPayable && auth()->user()->can('hr.update') && auth()->user()->can('finance.update'))<form method="POST" action="{{ route('hr.payroll.lines.pay',[$period,$line]) }}" style="display:flex;gap:6px;flex-wrap:wrap">@csrf<input type="hidden" name="submission_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><select name="source_account_id" required><option value="">Payment account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select><input type="date" name="payment_date" value="{{ now()->toDateString() }}" required><button class="btn" type="submit">Pay</button></form>
@elseif($period->status === 'approved' && ! $hasPayable)
No payment due
@else
Pending
@endif
@if($period->status !== 'draft' && ! $line->payment_transaction_id)<a href="{{ route('hr.payroll.lines.payslip',[$period,$line]) }}">Payslip</a>
@endif
</td></tr>
@endforeach
</tbody></table></div></div>
@if($period->status === 'draft' && auth()->user()->can('hr.update') && auth()->user()->can('finance.update'))<div class="card" style="padding:20px"><h2>Approve and accrue payroll</h2><p>Choose the salary expense and salary liability accounts. Approval posts a balanced journal and locks the reviewed employee amounts. Every line must have current documented evidence; any changed salary, attendance, leave or component requires a new review.</p><form method="POST" action="{{ route('hr.payroll.approve',$period) }}" class="form-grid">@csrf<label>Salary expense<select name="expense_ledger_id" required><option value="">Select posting account</option>@foreach($expenses as $account)<option value="{{ $account->id }}" @selected($account->code==='5410')>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select></label><label>Salaries payable<select name="payable_ledger_id" required><option value="">Select posting account</option>@foreach($payables as $account)<option value="{{ $account->id }}" @selected($account->code==='2131.01')>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select></label><label class="inline-check"><input type="checkbox" name="confirm_review" value="1" required>I verified attendance and each employee amount for this period.</label><div><button type="submit" class="btn">Approve payroll</button></div></form></div>@endif
@if($period->approvalJournal)<p class="muted" style="margin-top:16px">Accrual journal: {{ $period->approvalJournal->number }}</p>@endif
@endsection
