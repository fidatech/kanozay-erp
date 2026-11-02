<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\CompanySetting;
use App\Models\EmployeeAttendance;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Services\PayrollPeriodService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollPeriodController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        return view('hr.payroll.index', [
            'periods' => PayrollPeriod::with('currency')->withCount('lines')->orderByDesc('period_start')->paginate(20),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, PayrollPeriod $period)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        $period->load(['currency', 'approvalJournal', 'lines.employee', 'lines.payment']);
        $start = $period->period_start->toDateString();
        $end = $period->period_start->copy()->endOfMonth()->toDateString();
        $attendance = EmployeeAttendance::whereIn('employee_profile_id', $period->lines->pluck('employee_profile_id'))
            ->whereBetween('attendance_date', [$start, $end])->get()
            ->groupBy('employee_profile_id')->map(fn ($rows) => $rows->countBy('status')->all())->all();
        return view('hr.payroll.show', [
            'period' => $period,
            'attendance' => $attendance,
            'expenses' => LedgerAccount::where('type', 'expense')->where('is_active', true)->where('is_group', false)->orderBy('code')->get(),
            'payables' => LedgerAccount::where('type', 'liability')->where('is_active', true)->where('is_group', false)->orderBy('code')->get(),
            'accounts' => FinancialAccount::where('currency_id', $period->currency_id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, PayrollPeriodService $service)
    {
        abort_unless($request->user()->can('hr.create'), 403);
        $data = $request->validate([
            'month' => ['required', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/'],
            'currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
        ]);
        if ($data['month'] > now()->format('Y-m')) throw ValidationException::withMessages(['month' => 'Future payroll periods cannot be created.']);
        $period = $service->create($data['month'], (int) $data['currency_id'], $request->user()->id);
        return redirect()->route('hr.payroll.show', $period)->with('success', 'Draft created with salary snapshots. Review every amount before approval.');
    }

    public function updateLine(Request $request, PayrollPeriod $period, PayrollLine $line, PayrollPeriodService $service)
    {
        abort_unless($request->user()->can('hr.update'), 403);
        $request->merge(['payable_amount' => $this->normalize($request->input('payable_amount'))]);
        $data = $request->validate([
            'payable_amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/', 'numeric', 'min:0'],
            'adjustment_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $service->adjust($period, $line, $data['payable_amount'], $data['adjustment_reason'] ?? null);
        return back()->with('success', 'Draft amount updated.');
    }

    public function approve(Request $request, PayrollPeriod $period, PayrollPeriodService $service)
    {
        $this->authorizePosting($request);
        $data = $request->validate([
            'expense_ledger_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'payable_ledger_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'confirm_review' => ['accepted'],
        ]);
        $service->approve($period, (int) $data['expense_ledger_id'], (int) $data['payable_ledger_id'], $request->user()->id);
        return back()->with('success', 'Payroll approved and balanced salary liability journal posted.');
    }

    public function pay(Request $request, PayrollPeriod $period, PayrollLine $line, PayrollPeriodService $service)
    {
        $this->authorizePosting($request);
        $data = $request->validate([
            'source_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'submission_token' => ['required', 'uuid'],
        ]);
        if ($data['payment_date'] < $period->period_start->toDateString()) throw ValidationException::withMessages(['payment_date' => 'Payment date cannot precede the payroll period.']);
        $service->pay($period, $line, FinancialAccount::findOrFail($data['source_account_id']), $data['payment_date'], $data['submission_token'], $request->user()->id);
        return back()->with('success', 'Employee salary payment posted to cash and general ledger.');
    }

    public function payslip(Request $request, PayrollPeriod $period, PayrollLine $line)
    {
        abort_unless($request->user()->can('hr.view'), 403);
        abort_unless($period->status !== 'draft', 404);
        $line = $period->lines()->with(['employee', 'payment.journal'])->findOrFail($line->id);

        return view('hr.payroll.payslip', [
            'period' => $period->load('currency', 'approvalJournal'),
            'line' => $line,
            'company' => CompanySetting::first(),
        ]);
    }

    private function authorizePosting(Request $request): void
    {
        abort_unless($request->user()->can('hr.update') && $request->user()->can('finance.update'), 403);
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
