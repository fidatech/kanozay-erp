<?php

namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryAdvance;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Services\EmployeeSalaryAdvanceService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeSalaryAdvanceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        $advances = EmployeeSalaryAdvance::with(['employee', 'currency', 'transaction.journal', 'repayments'])->orderByDesc('issued_on')->paginate(25);
        foreach ($advances as $advance) {
            $outstanding = BigDecimal::of((string) $advance->amount);
            foreach ($advance->repayments as $repayment) $outstanding = $outstanding->minus((string) $repayment->amount);
            $advance->setAttribute('outstanding_amount', (string) $outstanding->toScale(4));
        }
        return view('hr.advances.index', [
            'advances' => $advances,
            'employees' => EmployeeProfile::where('is_active', true)->orderBy('employee_number')->get(),
            'accounts' => FinancialAccount::where('is_active', true)->with('currency')->orderBy('name')->get(),
            'receivables' => LedgerAccount::where('type', 'asset')->where('is_active', true)->where('is_group', false)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, EmployeeSalaryAdvanceService $service)
    {
        abort_unless($request->user()->can('hr.update') && $request->user()->can('finance.update'), 403);
        $request->merge(['amount' => $this->normalize($request->input('amount'))]);
        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:employee_profiles,id'],
            'source_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'receivable_ledger_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^[1-9][0-9]{0,15}(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'submission_token' => ['required', 'uuid'],
        ]);
        $service->issue($data, $request->user()->id);
        return redirect()->route('hr.advances.index')->with('success', 'Salary advance paid and receivable journal posted. Repayment requires separate review.');
    }

    public function repay(Request $request, EmployeeSalaryAdvance $advance, EmployeeSalaryAdvanceService $service)
    {
        abort_unless($request->user()->can('hr.update') && $request->user()->can('finance.update'), 403);
        $request->merge(['amount' => $this->normalize($request->input('amount'))]);
        $data = $request->validate([
            'destination_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^[1-9][0-9]{0,15}(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
            'submission_token' => ['required', 'uuid'],
        ]);
        $service->repay($advance, $data, $request->user()->id);
        return redirect()->route('hr.advances.index')->with('success', 'Repayment posted against the employee advance receivable.');
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        return str_replace(',', '', strtr($value, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>',',
        ]));
    }
}
