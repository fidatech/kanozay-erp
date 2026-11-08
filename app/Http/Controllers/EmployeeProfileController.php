<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\EmployeeDepartment;
use App\Services\PayrollWorkbookImportService;
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

        return view('hr.employees.index', [
            'employees' => $query->with(['salaryCurrency', 'department'])->paginate(20)->withQueryString(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'departments' => EmployeeDepartment::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $request->merge(['monthly_salary' => $this->normalizeNumber($request->input('monthly_salary'))]);
        $data = $request->validate($this->rules());
        $data['created_by'] = $request->user()->id;
        DB::transaction(function () use ($data) {
            $employee = EmployeeProfile::create($data);
            ActivityLog::record('hr.employee.created', 'employee_profile', $employee->id, 'Created employee profile', [
                'employee_number' => $employee->employee_number,
            ]);
        });

        return redirect()->route('workspace.page', ['hr', 'employees'])->with('success', 'Employee profile added. Payroll rules have not been applied.');
    }

    /**
     * Employment dates are evidence, not a shortcut to offboarding.
     * Deactivation belongs to the approved employee exit workflow,
     * which reconciles advances and returned equipment first.
     */
    public function update(Request $request, EmployeeProfile $employee)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate([
            'hired_on'=>['required','date','before_or_equal:today'],
            'evidence_reference'=>['required','string','min:3','max:180'],
            'reason'=>['required','string','min:10','max:1000'],
        ]);
        DB::transaction(function () use ($employee,$data) {
            $locked=EmployeeProfile::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if($locked->separated_on && $data['hired_on']>$locked->separated_on->toDateString()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'hired_on'=>'Hire date cannot follow an approved employee separation.',
                ]);
            }
            if($locked->hired_on?->toDateString()!==$data['hired_on'] &&
               DB::table('payroll_lines as line')
                ->join('payroll_periods as period','period.id','=','line.payroll_period_id')
                ->where('line.employee_profile_id',$locked->id)
                ->where('period.status','!=','draft')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'hired_on'=>'Existing posted payroll references this employee. Employment-date corrections require a controlled payroll reconciliation.',
                ]);
            }
            $previous=$locked->hired_on?->toDateString();
            $locked->update(['hired_on'=>$data['hired_on']]);
            ActivityLog::record('hr.employee.employment_date_verified','employee_profile',$locked->id,
                'Verified employment commencement date with evidence.',[
                    'previous'=>$previous,'hired_on'=>$data['hired_on'],
                    'evidence_reference'=>$data['evidence_reference'],
                    'reason'=>$data['reason'],
                ]);
        });
        return back()->with('success','Employment date verified without altering activation status or prior payroll.');
    }

    public function importPreview(Request $request, PayrollWorkbookImportService $importer)
    {
        abort_unless($request->user()->can('hr.import'), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
            'salary_currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
        ]);
        $path = $data['file']->getRealPath();
        $preview = $importer->preview($path, (int) $data['salary_currency_id']);

        return view('hr.employees.import-preview', [
            'preview' => $preview,
            'previewToken' => $importer->tokenFor($path, (int) $data['salary_currency_id']),
            'salaryCurrencyId' => (int) $data['salary_currency_id'],
            'salaryCurrency' => Currency::findOrFail($data['salary_currency_id']),
        ]);
    }

    public function assignDepartment(Request $request, EmployeeProfile $employee)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $data = $request->validate(['department_id' => ['nullable', Rule::exists('employee_departments', 'id')->where('is_active', true)]]);
        DB::transaction(function () use ($employee, $data) {
            $locked = EmployeeProfile::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked->update(['department_id' => $data['department_id'] ?? null]);
            ActivityLog::record('hr.employee.department', 'employee_profile', $locked->id, 'Department assignment updated for '.$locked->employee_number);
        });
        return back()->with('success', 'Employee department updated.');
    }

    public function importConfirm(Request $request, PayrollWorkbookImportService $importer)
    {
        abort_unless($request->user()->can('hr.import'), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
            'preview_token' => ['required', 'string', 'max:256'],
            'salary_currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
            'confirm_import' => ['accepted'],
        ]);

        $count = DB::transaction(function () use ($data, $request, $importer) {
            $count = $importer->confirm($data['file']->getRealPath(), $data['preview_token'], (int) $data['salary_currency_id'], $request->user());
            ActivityLog::record('hr.employee.imported', 'employee_profile', null, 'Imported employee profiles from the reviewed payroll workbook', [
                'record_count' => $count,
            ]);

            return $count;
        });

        return redirect()->route('workspace.page', ['hr', 'employees'])->with('success', $count.' employee profiles imported. The source deduction formula was not applied.');
    }

    private function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employee_profiles', 'employee_number')],
            'full_name' => ['required', 'string', 'max:180'],
            'father_name' => ['nullable', 'string', 'max:180'],
            'gender' => ['nullable', 'string', 'max:32'],
            'position' => ['nullable', 'string', 'max:180'],
            'department_id' => ['nullable', Rule::exists('employee_departments', 'id')->where('is_active', true)],
            'phone' => ['nullable', 'string', 'max:80'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'taxpayer_id' => ['nullable', 'string', 'max:100'],
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'hired_on'=>['nullable','date','before_or_equal:today'],
            'salary_currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
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
