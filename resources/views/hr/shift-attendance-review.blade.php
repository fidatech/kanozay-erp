@extends('layouts.kanozay')
@section('content')
<style>
.sar-wrap{background:white;border:1px solid #dfebf2;border-radius:12px;padding:21px;margin:15px 0}
.sar-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.sar-stat{background:#f3f8fd;border-radius:9px;padding:15px}.sar-stat strong{font-size:22px;display:block}
.sar-table{overflow:auto}.sar-table table{min-width:850px}
@media(max-width:850px){.sar-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="heading"><div><h1>Shift & Attendance Reconciliation</h1><p>Compare independently approved employee shift schedules against actual attendance records</p></div>
<a class="btn btn-light" href="{{ route('workspace.page',['hr','attendance']) }}">Attendance</a></div>
<section class="sar-wrap"><form method="GET" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
<label>Month<input type="month" name="month" value="{{ $month }}" required></label>
<label>Employee<select name="employee_id"><option value="">All employees</option>
@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected($selectedEmployee==$employee->id)>{{ $employee->employee_number }} — {{ $employee->full_name }}</option>@endforeach
</select></label>
<button class="btn">Filter</button>
<a class="btn btn-light" href="{{ route('hr.shift-attendance.export',array_filter(['month'=>$month,'employee_id'=>$selectedEmployee])) }}">Download CSV</a>
</form></section>
<section class="sar-stats">
<div class="sar-stat"><small>Approved shifts</small><strong>{{ $counts['planned'] }}</strong></div>
<div class="sar-stat"><small>Present</small><strong>{{ $counts['present'] }}</strong></div>
<div class="sar-stat"><small>Absent</small><strong>{{ $counts['absent'] }}</strong></div>
<div class="sar-stat"><small>Leave / holiday</small><strong>{{ $counts['leave']+$counts['holiday'] }}</strong></div>
<div class="sar-stat"><small>Not recorded</small><strong>{{ $counts['not_recorded'] }}</strong></div>
</section>
<section class="sar-wrap"><h2>Reviewed shifts and actual attendance</h2>
<p class="muted">This is a reconciliation of approved schedules and day-level attendance. It does not infer clock-in times, late arrivals, overtime, salary deductions or payroll adjustments that have not been recorded.</p>
<div class="sar-table"><table><thead><tr><th>Shift</th><th>Employee</th><th>Date</th><th>Hours window</th><th>Attendance</th><th>Approval</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row['number'] }}</td>
<td>{{ $row['employee_name'] }}<br><small>{{ $row['employee_number'] }}</small></td>
<td>{{ $row['date'] }}</td><td>{{ $row['starts_at'] }} – {{ $row['ends_at'] }}</td>
<td>{{ ucwords(str_replace('_',' ',$row['attendance_status'])) }}</td><td>{{ ucfirst($row['shift_status']) }}</td></tr>
@empty<tr><td colspan="6">No approved shifts found for this filter.</td></tr>@endforelse
</tbody></table></div></section>
@endsection