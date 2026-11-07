<?php
namespace App\Http\Controllers;

use App\Models\{Currency,LedgerAccount,PayrollPeriod,PayrollTaxPolicy};
use App\Services\PayrollTaxPolicyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Brick\Math\BigDecimal;

class PayrollTaxPolicyController extends Controller {
 private function read(Request $r):void {
  abort_unless($r->user()->canAny(['hr.view','finance.view','administration.view']),403);
 }
 private function write(Request $r):void {
  abort_unless($r->user()->can('administration.update') ||
    ($r->user()->can('hr.update')&&$r->user()->can('finance.update')),403);
 }
 public function index(Request $request,PayrollTaxPolicyService $service){
  $this->read($request);
  $q=$request->validate(['period_id'=>['nullable','integer','exists:payroll_periods,id'],
   'policy_id'=>['nullable','integer','exists:payroll_tax_policies,id']]);
  $period=!empty($q['period_id'])?PayrollPeriod::with('lines.employee')->findOrFail($q['period_id']):null;
  $selected=!empty($q['policy_id'])?PayrollTaxPolicy::with('brackets')->findOrFail($q['policy_id']):null;
  $preview=[];
  if($period && $selected && $selected->currency_id===$period->currency_id &&
     $selected->status==='approved'){
   foreach($period->lines as $line){
    $base=BigDecimal::of((string)$line->salary_snapshot);
    if($selected->basis==='salary_plus_allowances')
     foreach($line->components()->where('kind','allowance')->get() as $component)
      $base=$base->plus((string)$component->amount);
    $preview[]=['employee'=>$line->employee?->full_name,'number'=>$line->employee?->employee_number,
     'gross'=>(string)$base->toScale(4),
     'tax'=>$service->calculate($selected,(string)$base),
     'net'=>$line->payable_amount];
   }
  }
  return view('hr.payroll.tax-policies',[
   'policies'=>PayrollTaxPolicy::with(['brackets','currency','ledger'])->orderByDesc('effective_from')->paginate(25),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
   'liabilities'=>LedgerAccount::where('type','liability')->where('is_active',true)
      ->where('is_group',false)->orderBy('code')->get(),
   'periods'=>PayrollPeriod::with('currency')->latest('period_start')->limit(100)->get(),
   'period'=>$period,'selected'=>$selected,'preview'=>$preview,
  ]);
 }
 public function store(Request $request,PayrollTaxPolicyService $service){
  $this->write($request);
  $request->merge(['code'=>strtoupper(trim((string)$request->input('code')))]);
  $data=$request->validate([
   'code'=>['required','regex:/^[A-Z0-9][A-Z0-9._-]{0,59}$/',Rule::unique('payroll_tax_policies','code')],
   'name'=>['required','string','min:3','max:160'],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'effective_from'=>['required','date','date_format:Y-m-d'],
   'basis'=>['required',Rule::in(['base_salary','salary_plus_allowances'])],
   'liability_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
   'notes'=>['required','string','min:10','max:3000'],
   'brackets'=>['required','array','min:1','max:20'],
   'brackets.*.lower_bound'=>['required','numeric','min:0','decimal:0,4','max:9999999999999999.9999'],
   'brackets.*.upper_bound'=>['nullable','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
   'brackets.*.fixed_tax'=>['required','numeric','min:0','decimal:0,4','max:9999999999999999.9999'],
   'brackets.*.marginal_percent'=>['required','numeric','between:0,100','decimal:0,4'],
  ]);
  $policy=$service->create($data,$request->user()->id);
  return redirect()->route('hr.payroll.tax-policies.index')->with('success',
   'Draft policy '.$policy->code.' created. Independent finance review is required before use.');
 }
 public function approve(Request $request,PayrollTaxPolicy $policy,PayrollTaxPolicyService $service){
  $this->write($request);
  $data=$request->validate(['approval_reference'=>['required','string','min:3','max:180']]);
  $service->approve($policy,$data['approval_reference'],$request->user()->id);
  return back()->with('success','Salary withholding policy approved.');
 }
 public function apply(Request $request,PayrollTaxPolicy $policy,PayrollTaxPolicyService $service){
  $this->write($request);
  $data=$request->validate([
   'payroll_period_id'=>['required','integer','exists:payroll_periods,id'],
   'confirm_review'=>['accepted'],
  ]);
  $out=$service->apply($policy,PayrollPeriod::findOrFail($data['payroll_period_id']),$request->user()->id);
  return redirect()->route('hr.payroll.show',$data['payroll_period_id'])
   ->with('success',$out['lines'].' employee tax calculations recorded. Total withholding: '.$out['total']);
 }
}