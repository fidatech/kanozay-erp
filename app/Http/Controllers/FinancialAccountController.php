<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Services\Accounting\EntityAccountProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class FinancialAccountController extends Controller {
 public function index(Request $request){abort_unless($request->user()->can('administration.view'),403);$filter=$request->validate(['type'=>['nullable',Rule::in(['cashbox','bank','sarafi'])]]);$accounts=FinancialAccount::with('currency')->when($filter['type']??null,fn($q,$type)=>$q->where('type',$type))->latest()->paginate(25)->withQueryString();return view('finance.accounts.index',['accounts'=>$accounts,'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),'type'=>$filter['type']??null]);}
 public function store(Request $request,EntityAccountProvisioner $provisioner){abort_unless($request->user()->can('administration.update'),403);$data=$request->validate(['type'=>['required',Rule::in(['cashbox','bank','sarafi'])],'name'=>['required','string','max:160'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'institution'=>['nullable','string','max:160'],'account_number'=>['nullable','string','max:120'],'contact'=>['nullable','string','max:160'],'notes'=>['nullable','string','max:2000']]);DB::transaction(function()use($data,$request,$provisioner){$account=FinancialAccount::create($data+['created_by'=>$request->user()->id,'is_active'=>true]);$provisioner->provision($account->type,$account->id,$account->name,$account->currency_id);ActivityLog::record('financial_account.created','financial_account',$account->id,'Financial account created: '.$account->name);});return redirect()->route('finance.accounts.index')->with('status','Financial account created with linked ledger account.');}
 public function update(Request $request,FinancialAccount $account){abort_unless($request->user()->can('administration.update'),403);$data=$request->validate(['name'=>['required','string','max:160'],'institution'=>['nullable','string','max:160'],'account_number'=>['nullable','string','max:120'],'contact'=>['nullable','string','max:160'],'notes'=>['nullable','string','max:2000'],'is_active'=>['required','boolean']]);$account->update($data);ActivityLog::record('financial_account.updated','financial_account',$account->id,'Financial account updated: '.$account->name);return back()->with('status','Financial account updated.');}
}
