<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class LedgerAccountController extends Controller {
 public const TYPES=['asset','liability','equity','income','expense'];
 public function index(Request $request){
  abort_unless($request->user()->can('administration.view'),403);
  $query=LedgerAccount::with('parent')->withCount('children')->orderBy('code');
  if($request->filled('type')&&in_array($request->type,self::TYPES,true))$query->where('type',$request->type);
  if($request->filled('q')){$term=substr(trim((string)$request->input('q')),0,100);$query->where(fn($q)=>$q->where('code','like','%'.$term.'%')->orWhere('name','like','%'.$term.'%')->orWhere('description','like','%'.$term.'%'));}
  $accounts=$query->paginate(40)->withQueryString();
  $ids=$accounts->getCollection()->pluck('id')->all();
  $used=[];
  $references=[['journal_lines','ledger_account_id'],['entity_ledger_accounts','ledger_account_id'],['money_transactions','counterpart_ledger_account_id'],['payroll_periods','expense_ledger_id'],['payroll_periods','payable_ledger_id'],['employee_salary_advances','receivable_ledger_id']];
  foreach($references as [$table,$column]){
   foreach(DB::table($table)->whereIn($column,$ids)->distinct()->pluck($column) as $id)$used[(int)$id]=true;
  }
  $deletableIds=$accounts->getCollection()->filter(fn($account)=>$account->children_count===0&&!isset($used[$account->id]))->pluck('id')->all();
  return view('admin.accounts.index',['accounts'=>$accounts,'deletableIds'=>$deletableIds,'groups'=>LedgerAccount::where('is_group',true)->where('is_active',true)->orderBy('code')->get(),'types'=>self::TYPES]);
 }
 private function validated(Request $request,?LedgerAccount $account=null):array {
 $data=$request->validate(['code'=>['required','string','max:24','regex:/^[A-Za-z0-9.-]+$/',Rule::unique('ledger_accounts','code')->ignore($account?->id)],'name'=>['required','string','max:160'],'type'=>['required',Rule::in(self::TYPES)],'parent_id'=>['nullable','integer',Rule::exists('ledger_accounts','id')],'is_group'=>['required','boolean'],'is_active'=>['required','boolean'],'description'=>['nullable','string','max:500']]);
 if(!empty($data['parent_id'])){$parent=LedgerAccount::findOrFail($data['parent_id']);if(!$parent->is_group||$parent->type!==$data['type']||!$parent->is_active)throw ValidationException::withMessages(['parent_id'=>'Choose an active group of the same account type.']);if($account){$cursor=$parent;while($cursor){if($cursor->id===$account->id)throw ValidationException::withMessages(['parent_id'=>'An account cannot be its own ancestor.']);$cursor=$cursor->parent;}}}
 if($account&&$account->children()->exists()&&(!$data['is_group']||$data['type']!==$account->type||!$data['is_active']))throw ValidationException::withMessages(['type'=>'An account with child accounts must remain an active group of the same type.']);
 if($account && !$data['is_active'] && DB::table('entity_ledger_accounts')->where('ledger_account_id',$account->id)->exists())
  throw ValidationException::withMessages(['is_active'=>'A mapped customer or supplier account cannot be disabled while its entity link exists.']);
 if($account && (DB::table('journal_lines')->where('ledger_account_id',$account->id)->exists() || DB::table('entity_ledger_accounts')->where('ledger_account_id',$account->id)->exists())) {
  if($data['type']!==$account->type || (bool)$data['is_group']!==$account->is_group) throw ValidationException::withMessages(['type'=>'Accounts linked to journals or entity ledgers cannot change account type or posting/group kind.']);
  if($data['code']!==$account->code) throw ValidationException::withMessages(['code'=>'Accounts linked to journals or entity ledgers cannot change their account code.']);
 }
 return $data;
 }
 public function store(Request $request){abort_unless($request->user()->can('administration.update'),403);$data=$this->validated($request);DB::transaction(function()use($data,$request){$a=LedgerAccount::create($data+['created_by'=>$request->user()->id]);ActivityLog::record('account.created','ledger_account',$a->id,'Account '.$a->code.' created');});return back()->with('status','Account created successfully.');}
 public function update(Request $request,LedgerAccount $account){abort_unless($request->user()->can('administration.update'),403);$data=$this->validated($request,$account);DB::transaction(function()use($data,$account){$account->update($data);ActivityLog::record('account.updated','ledger_account',$account->id,'Account '.$account->code.' updated');});return back()->with('status','Account updated successfully.');}
 public function destroy(Request $request,LedgerAccount $account){
  abort_unless($request->user()->can('administration.update'),403);
  DB::transaction(function()use($account){
   $account=LedgerAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
   if($account->children()->exists() || DB::table('journal_lines')->where('ledger_account_id',$account->id)->exists() || DB::table('entity_ledger_accounts')->where('ledger_account_id',$account->id)->exists() || DB::table('money_transactions')->where('counterpart_ledger_account_id',$account->id)->exists() || DB::table('payroll_periods')->where('expense_ledger_id',$account->id)->orWhere('payable_ledger_id',$account->id)->exists() || DB::table('employee_salary_advances')->where('receivable_ledger_id',$account->id)->exists())
    throw ValidationException::withMessages(['account'=>'This account is in use or has child accounts and cannot be deleted.']);
   try{$account->delete();}catch(\Illuminate\Database\QueryException $exception){throw ValidationException::withMessages(['account'=>'This account is referenced by another record and cannot be deleted.']);}
   ActivityLog::record('account.deleted','ledger_account',$account->id,'Unused account '.$account->code.' deleted');
  });
  return back()->with('status','Unused account deleted successfully.');
 }

}
