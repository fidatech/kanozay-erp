<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeProfileController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);

        $query = EmployeeProfile::query()->orderBy('employee_number');
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('employee_number', 'like', '%'.$search.'%')
                    ->orWhere('position', 'like', '%'.$search.'%');
            });
        }
        if ($request->input('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('status') === 'inactive') {
            $query->where('is_active', false);
        }

        return view('hr.employees.index', ['employees' => $query->paginate(20)->withQueryString()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $request->merge(['monthly_salary' => $this->normalizeNumber($request->input('monthly_salary'))]);
        $data = $request->validate($this->rules());
        $data['created_by'] = $request->user()->id;
        $employee = DB::transaction(function () use ($data) {
            $employee = EmployeeProfile::create($data);
            ActivityLog::record('hr.employee.created', 'employee_profile', $employee->id, 'Created employee profile', [
                'employee_number' => $employee->employee_number,
            ]);

            return $employee;
        });

        return redirect()->route('workspace.page', ['hr', 'employees'])->with('success', 'Employee profile added. Payroll rules have not been applied.');
    }

    public function update(Request $request, EmployeeProfile $employee)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($employee, $data) {
            $employee->forceFill(['is_active' => (bool) $data['is_active']])->save();
            ActivityLog::record('hr.employee.updated', 'employee_profile', $employee->id, 'Updated employee profile', [
                'employee_number' => $employee->employee_number,
                'is_active' => $employee->is_active,
            ]);
        });

        return redirect()->route('workspace.page', ['hr', 'employees'])->with('success', 'Employee profile updated.');
    }

    private function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employee_profiles', 'employee_number')],
            'full_name' => ['required', 'string', 'max:180'],
            'father_name' => ['nullable', 'string', 'max:180'],
            'gender' => ['nullable', 'string', 'max:32'],
            'position' => ['nullable', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:80'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'taxpayer_id' => ['nullable', 'string', 'max:100'],
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
        ];
    }

    private function normalizeNumber(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',', '،' => ',',
        ]);

        return str_replace(',', '', $value);
    }
}
