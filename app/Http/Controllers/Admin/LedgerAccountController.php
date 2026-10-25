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
 public function index(Request $request){abort_unless($request->user()->can('administration.view'),403);$query=LedgerAccount::with('parent')->orderBy('code');if($request->filled('type')&&in_array($request->type,self::TYPES,true))$query->where('type',$request->type);return view('admin.accounts.index',['accounts'=>$query->paginate(40)->withQueryString(),'groups'=>LedgerAccount::where('is_group',true)->where('is_active',true)->orderBy('code')->get(),'types'=>self::TYPES]);}
 private function validated(Request $request,?LedgerAccount $account=null):array {
 $data=$request->validate(['code'=>['required','string','max:24','regex:/^[A-Za-z0-9.-]+$/',Rule::unique('ledger_accounts','code')->ignore($account?->id)],'name'=>['required','string','max:160'],'type'=>['required',Rule::in(self::TYPES)],'parent_id'=>['nullable','integer',Rule::exists('ledger_accounts','id')],'is_group'=>['required','boolean'],'is_active'=>['required','boolean'],'description'=>['nullable','string','max:500']]);
 if(!empty($data['parent_id'])){$parent=LedgerAccount::findOrFail($data['parent_id']);if(!$parent->is_group||$parent->type!==$data['type']||!$parent->is_active)throw ValidationException::withMessages(['parent_id'=>'Choose an active group of the same account type.']);if($account){$cursor=$parent;while($cursor){if($cursor->id===$account->id)throw ValidationException::withMessages(['parent_id'=>'An account cannot be its own ancestor.']);$cursor=$cursor->parent;}}}
 if($account&&$account->children()->exists()&&(!$data['is_group']||$data['type']!==$account->type||!$data['is_active']))throw ValidationException::withMessages(['type'=>'An account with child accounts must remain an active group of the same type.']);
 return $data;
 }
 public function store(Request $request){abort_unless($request->user()->can('administration.update'),403);$data=$this->validated($request);DB::transaction(function()use($data,$request){$a=LedgerAccount::create($data+['created_by'=>$request->user()->id]);ActivityLog::record('account.created','ledger_account',$a->id,'Account '.$a->code.' created');});return back()->with('status','Account created successfully.');}
 public function update(Request $request,LedgerAccount $account){abort_unless($request->user()->can('administration.update'),403);$data=$this->validated($request,$account);DB::transaction(function()use($data,$account){$account->update($data);ActivityLog::record('account.updated','ledger_account',$account->id,'Account '.$account->code.' updated');});return back()->with('status','Account updated successfully.');}
}
