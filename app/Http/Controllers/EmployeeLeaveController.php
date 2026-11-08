<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmployeeLeaveRequest;
use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['status'] ?? null;
        return view('hr.leave.index', [
            'requests' => EmployeeLeaveRequest::with('employee')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->orderByDesc('starts_on')->paginate(25)->withQueryString(),
            'employees' => EmployeeProfile::where('is_active', true)->orderBy('employee_number')->get(),
            'statusFilter' => $status,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $data = $request->validate([
            'employee_profile_id' => ['required', 'exists:employee_profiles,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $employee = EmployeeProfile::whereKey($data['employee_profile_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $overlap = EmployeeLeaveRequest::where('employee_profile_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
            if ($overlap) throw ValidationException::withMessages(['starts_on' => 'An active leave request already overlaps these dates.']);
            $record = EmployeeLeaveRequest::create($data + ['status' => 'pending', 'created_by' => $request->user()->id]);
            ActivityLog::record('hr.leave.requested', 'employee_leave_request', $record->id, 'Leave requested for employee '.$employee->employee_number);
        });
        return back()->with('success', 'Leave request recorded for review. No salary calculation was changed.');
    }

    public function decide(Request $request, EmployeeLeaveRequest $leave)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($leave, $data, $request) {
            $locked = EmployeeLeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if($data['decision']==='approved'){
                $periods=DB::table('payroll_lines as line')
                    ->join('payroll_periods as period','period.id','=','line.payroll_period_id')
                    ->where('line.employee_profile_id',$locked->employee_profile_id)
                    ->whereIn('period.status',['approved','paid'])
                    ->pluck('period.period_start');
                foreach($periods as $periodStart){
                    $monthEnd=\Carbon\CarbonImmutable::parse($periodStart)->endOfMonth()->toDateString();
                    $monthStart=\Carbon\CarbonImmutable::parse($periodStart)->startOfMonth()->toDateString();
                    if($monthStart<=$locked->ends_on->toDateString() &&
                       $monthEnd>=$locked->starts_on->toDateString()){
                        throw ValidationException::withMessages([
                            'decision'=>'This leave overlaps an approved payroll period. Complete a separate finance-reviewed payroll correction before retroactive approval.',
                        ]);
                    }
                }
            }
            if ($locked->status !== 'pending') throw ValidationException::withMessages(['decision' => 'This leave request has already been decided.']);
            $locked->update([
                'status' => $data['decision'], 'decision_note' => $data['decision_note'] ?? null,
                'decided_by' => $request->user()->id, 'decided_at' => now(),
            ]);
            ActivityLog::record('hr.leave.decided', 'employee_leave_request', $locked->id, 'Leave request '.$data['decision']);
        });
        return back()->with('success', 'Leave decision recorded. Attendance and payroll remain subject to separate review.');
    }
}
