<?php
namespace App\Http\Controllers;
use App\Models\LedgerAccount;
use App\Models\PayrollLine;
use App\Models\PayrollLineComponent;
use App\Models\PayrollPeriod;
use App\Services\PayrollComponentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollComponentController extends Controller {
 public function index(Request $request) {
  abort_unless($request->user()->canAny(['hr.view','finance.view','administration.view']),403);
  return view('hr.payroll.components',[
   'components'=>PayrollLineComponent::with(['line.employee','line.period.currency','ledger'])
    ->latest('id')->paginate(50),
   'periods'=>PayrollPeriod::with('currency')->orderByDesc('period_start')->limit(50)->get(),
  ]);
 }
 public function store(Request $request,PayrollPeriod $period,PayrollLine $line,PayrollComponentService $service) {
  abort_unless($request->user()->can('administration.update')||
    ($request->user()->can('hr.update') && $request->user()->can('finance.update')),403);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'kind'=>['required',Rule::in(['allowance','deduction'])],
   'label'=>['required','string','min:2','max:120'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:99999999999999.9999'],
   'ledger_account_id'=>['required','integer',Rule::exists('ledger_accounts','id')->where('is_group',false)->where('is_active',true)],
   'approval_reference'=>['required','string','min:3','max:180'],
   'reason'=>['required','string','min:8','max:2500'],
  ]);
  $service->add($period,$line,$data,$request->user()->id);
  return back()->with('success','Itemized payroll component saved. Payroll journal posts only after period approval.');
 }
}
