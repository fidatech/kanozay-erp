<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmployeeDepartment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeDepartmentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        return view('hr.departments.index', ['departments' => EmployeeDepartment::withCount('employees')->orderBy('code')->paginate(25)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employee_departments', 'code')],
            'name' => ['required', 'string', 'max:180'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $department = EmployeeDepartment::create($data + ['is_active' => true, 'created_by' => $request->user()->id]);
            ActivityLog::record('hr.department.created', 'employee_department', $department->id, 'Department created: '.$department->code);
        });
        return back()->with('success', 'Department added.');
    }

    public function update(Request $request, EmployeeDepartment $department)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($department, $data) {
            $locked = EmployeeDepartment::whereKey($department->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            ActivityLog::record('hr.department.updated', 'employee_department', $locked->id, 'Department updated: '.$locked->code);
        });
        return back()->with('success', 'Department updated. Existing employee assignments were preserved.');
    }
}
