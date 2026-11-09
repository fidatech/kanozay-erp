<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\PayrollPeriod;
use App\Models\PayrollTaxPolicy;
use App\Services\PayrollTaxRemittanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PayrollTaxRemittanceController extends Controller
{
    private function gate(Request $request,bool $write=false):void
    {
        abort_unless($request->user()->canAny($write
            ?['finance.update','administration.update']
            :['hr.view','finance.view','administration.view','reports.view']),403);
    }

    public function index(Request $request)
    {
        $this->gate($request);
        $filter=$request->validate([
            'status'=>['nullable',Rule::in(['submitted','approved','rejected','posted'])],
            'period_id'=>['nullable','integer','exists:payroll_periods,id'],
        ]);
        $records=DB::table('payroll_tax_remittances as payment')
            ->join('payroll_periods as period',
                'period.id','=','payment.payroll_period_id')
            ->join('payroll_tax_policies as policy',
                'policy.id','=','payment.payroll_tax_policy_id')
            ->join('financial_accounts as account',
                'account.id','=','payment.financial_account_id')
            ->join('currencies as currency',
                'currency.id','=','payment.currency_id')
            ->leftJoin('journal_entries as journal',
                'journal.id','=','payment.journal_entry_id')
            ->select('payment.*','period.period_start',
                'policy.code as policy_code','policy.name as policy_name',
                'account.name as account_name','currency.code as currency_code',
                'journal.number as journal_number','journal.status as journal_status')
            ->when($filter['status']??null,
                fn($q,$status)=>$q->where('payment.status',$status))
            ->when($filter['period_id']??null,
                fn($q,$id)=>$q->where('payment.payroll_period_id',$id))
            ->orderByDesc('payment.id')->paginate(25)->withQueryString();
        $periods=PayrollPeriod::with('currency')
            ->whereIn('status',['approved','paid'])
            ->orderByDesc('period_start')->limit(80)->get();
        $policies=PayrollTaxPolicy::with('currency')
            ->where('status','approved')->orderByDesc('effective_from')->get();
        $accounts=FinancialAccount::with('currency')
            ->whereIn('type',['bank','cashbox'])
            ->where('is_active',true)->orderBy('name')->get();
        return view('finance.payroll-tax-remittances',compact(
            'records','periods','policies','accounts','filter'));
    }

    public function store(Request $request,PayrollTaxRemittanceService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'payroll_period_id'=>['required','integer','exists:payroll_periods,id'],
            'payroll_tax_policy_id'=>['required','integer','exists:payroll_tax_policies,id'],
            'financial_account_id'=>['required','integer','exists:financial_accounts,id'],
            'amount'=>['required','regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/',
                'numeric','gt:0'],
            'remit_on'=>['required','date','date_format:Y-m-d','before_or_equal:today'],
            'evidence_reference'=>['required','string','min:4','max:180'],
            'source_notes'=>['required','string','min:60','max:5000'],
            'submission_token'=>['required','uuid'],
            'confirm_original_withholding'=>['accepted'],
        ]);
        $service->submit($data,(int)$request->user()->id);
        return redirect()->route('finance.payroll-tax-remittances.index')
            ->with('success','Original-currency withholding proposed. A separate finance reviewer must approve; no journal or bank payment has been made.');
    }

    public function review(Request $request,int $remittance,
        PayrollTaxRemittanceService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'review_reference'=>['required','string','min:4','max:180'],
            'review_notes'=>['required','string','min:80','max:5000'],
            'confirm_independent_tax_review'=>['accepted'],
        ]);
        $service->review($remittance,$data,(int)$request->user()->id);
        return back()->with('success',
            'Independent withholding remittance decision recorded. No source GL or bank payment was changed.');
    }

    public function post(Request $request,int $remittance,
        PayrollTaxRemittanceService $service)
    {
        $this->gate($request,true);
        $request->validate([
            'confirm_source_liability'=>['accepted'],
            'confirm_original_payment'=>['accepted'],
        ]);
        $record=$service->post($remittance,(int)$request->user()->id);
        return back()->with('success','Source-verified employee withholding liability paid and balanced original-currency journal #'.$record->journal_entry_id.' posted.');
    }
}
