<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceController extends Controller
{
    private const STATUSES = ['present', 'absent', 'leave', 'holiday'];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        $month = (string) $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month), 422);
        return view('hr.attendance.index', [
            'month' => $month,
            'entries' => EmployeeAttendance::with('employee')
                ->whereDate('attendance_date', '>=', $month.'-01')
                ->whereDate('attendance_date', '<=', date('Y-m-t', strtotime($month.'-01')))
                ->orderByDesc('attendance_date')->orderBy('id')->paginate(30)->withQueryString(),
            'employees' => EmployeeProfile::where('is_active', true)->orderBy('employee_number')->get(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $data = $request->validate([
            'employee_profile_id' => ['required', 'exists:employee_profiles,id'],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $employee = EmployeeProfile::whereKey($data['employee_profile_id'])->where('is_active', true)->firstOrFail();
            $this->assertPayrollOpen($employee->id,$data['attendance_date']);
            if (EmployeeAttendance::where('employee_profile_id', $employee->id)->whereDate('attendance_date', $data['attendance_date'])->exists()) {
                throw ValidationException::withMessages(['attendance_date' => 'Attendance for this employee and day already exists. Use the correction form.']);
            }
            $entry = EmployeeAttendance::create($data + ['recorded_by' => $request->user()->id]);
            ActivityLog::record('hr.attendance.recorded', 'employee_attendance', $entry->id, 'Attendance recorded for employee '.$employee->employee_number);
        });
        return back()->with('success', 'Attendance recorded. Payroll amounts remain unchanged until reviewed.');
    }

    public function correct(Request $request, EmployeeAttendance $entry)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'correction_reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($entry, $data, $request) {
            $locked = EmployeeAttendance::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->assertPayrollOpen($locked->employee_profile_id,$locked->attendance_date->toDateString());
            if ($locked->status === $data['status']) throw ValidationException::withMessages(['status' => 'Choose a different status for the correction.']);
            $before = $locked->status;
            $locked->update([
                'status' => $data['status'], 'correction_reason' => $data['correction_reason'],
                'corrected_by' => $request->user()->id,
            ]);
            ActivityLog::record('hr.attendance.corrected', 'employee_attendance', $locked->id, 'Attendance corrected', ['from' => $before, 'to' => $data['status'], 'reason' => $data['correction_reason']]);
        });
        return back()->with('success', 'Attendance corrected with an audit record.');
    }
    private function assertPayrollOpen(int $employeeId,string $date):void
    {
        $period=\Carbon\CarbonImmutable::parse($date)->startOfMonth()->toDateString();
        if(DB::table('payroll_lines as line')
            ->join('payroll_periods as pay','pay.id','=','line.payroll_period_id')
            ->where('line.employee_profile_id',$employeeId)
            ->where('pay.period_start',$period)
            ->whereIn('pay.status',['approved','paid'])->exists()){
            throw ValidationException::withMessages([
                'attendance_date'=>'Payroll for this employee and month is already posted. Record a separate authorized payroll correction before changing historical attendance.',
            ]);
        }
    }
}
