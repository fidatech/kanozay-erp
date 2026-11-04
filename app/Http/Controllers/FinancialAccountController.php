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
 public function index(Request $request){abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'finance.update', 'reports.view']),403);$filter=$request->validate(['type'=>['nullable',Rule::in(['cashbox','bank','sarafi'])]]);$accounts=FinancialAccount::with('currency')->when($filter['type']??null,fn($q,$type)=>$q->where('type',$type))->latest()->paginate(25)->withQueryString();return view('finance.accounts.index',['accounts'=>$accounts,'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),'type'=>$filter['type']??null]);}
 public function store(Request $request,EntityAccountProvisioner $provisioner){abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);$data=$request->validate(['type'=>['required',Rule::in(['cashbox','bank','sarafi'])],'name'=>['required','string','max:160'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'institution'=>['nullable','string','max:160'],'account_number'=>['nullable','string','max:120'],'contact'=>['nullable','string','max:160'],'notes'=>['nullable','string','max:2000']]);$account=DB::transaction(function()use($data,$request,$provisioner){$account=FinancialAccount::create($data+['created_by'=>$request->user()->id,'is_active'=>true]);$provisioner->provision($account->type,$account->id,$account->name,$account->currency_id);ActivityLog::record('financial_account.created','financial_account',$account->id,'Financial account created: '.$account->name);return $account;});if($request->expectsJson())return response()->json(['message'=>'Financial account created.','account'=>['id'=>$account->id,'name'=>$account->name,'type'=>$account->type,'currency_id'=>$account->currency_id]],201);return redirect()->route('finance.accounts.index')->with('status','Financial account created with linked ledger account.');}

 private function assertUnused(FinancialAccount $account): \Illuminate\Support\Collection {
  if(DB::table('money_transactions')->where('source_account_id',$account->id)->orWhere('destination_account_id',$account->id)->exists())
   throw \Illuminate\Validation\ValidationException::withMessages(['currency_id'=>'This financial account has transactions. Its currency and ledger cannot be changed or deleted.']);
  $links=DB::table('entity_ledger_accounts')->where('entity_type',$account->type)->where('entity_id',$account->id)->get();
  foreach($links as $link){
   if(DB::table('journal_lines')->where('ledger_account_id',$link->ledger_account_id)->exists())
    throw \Illuminate\Validation\ValidationException::withMessages(['currency_id'=>'This financial account has journal entries. Its currency and ledger cannot be changed or deleted.']);
  }
  return $links;
 }
 private function detachUnusedLedgers(\Illuminate\Support\Collection $links):void {
  foreach($links as $link){
   DB::table('entity_ledger_accounts')->where('id',$link->id)->delete();
   try {
    \App\Models\LedgerAccount::whereKey($link->ledger_account_id)->firstOrFail()->delete();
   } catch(\Illuminate\Database\QueryException $e) {
    throw \Illuminate\Validation\ValidationException::withMessages(['currency_id'=>'The linked ledger is referenced by another record and cannot be removed.']);
   }
  }
 }
 public function update(Request $request,FinancialAccount $account){
  abort_unless($request->user()->canAny(['administration.update','finance.update']),403);
  $data=$request->validate([
   'name'=>['required','string','max:160'],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'institution'=>['nullable','string','max:160'],
   'account_number'=>['nullable','string','max:120'],
   'contact'=>['nullable','string','max:160'],
   'notes'=>['nullable','string','max:2000'],
   'is_active'=>['required','boolean'],
  ]);
  DB::transaction(function()use($account,$data,$request){
   $account=FinancialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
   $oldCurrencyId=(int)$account->currency_id;
   $newCurrencyId=(int)$data['currency_id'];
   $changedCurrency=$oldCurrencyId!==$newCurrencyId;
   if($changedCurrency){
    $links=$this->assertUnused($account);
    $this->detachUnusedLedgers($links);
   }
   $account->update($data);
   if($changedCurrency){
    app(EntityAccountProvisioner::class)->provision($account->type,$account->id,$account->name,$newCurrencyId);
   }else{
    // Keep the displayed ledger account name in sync for a safe rename.
    $links=DB::table('entity_ledger_accounts')->where('entity_type',$account->type)->where('entity_id',$account->id)->get();
    foreach($links as $link){
     $currency=Currency::find($link->currency_id);
     $ledger=\App\Models\LedgerAccount::find($link->ledger_account_id);
     if($ledger && $currency) $ledger->update(['name'=>$account->name.' — '.$currency->code.' ('.ucfirst($link->purpose).')']);
    }
   }
   ActivityLog::record('financial_account.updated','financial_account',$account->id,
    'Financial account updated: '.$account->name,
    ['old_currency_id'=>$oldCurrencyId,'new_currency_id'=>$newCurrencyId]);
  });
  return redirect()->route('finance.accounts.index')->with('status','Financial account updated successfully.');
 }
 public function destroy(Request $request,FinancialAccount $account){
  abort_unless($request->user()->canAny(['administration.update','finance.update']),403);
  DB::transaction(function()use($account){
   $account=FinancialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
   $links=$this->assertUnused($account);
   $this->detachUnusedLedgers($links);
   try{$account->delete();}
   catch(\Illuminate\Database\QueryException $e){
    throw \Illuminate\Validation\ValidationException::withMessages(['account'=>'This financial account is referenced by another record and cannot be deleted.']);
   }
   ActivityLog::record('financial_account.deleted','financial_account',$account->id,'Unused financial account deleted: '.$account->name);
  });
  return redirect()->route('finance.accounts.index')->with('status','Unused financial account deleted.');
 }
}
