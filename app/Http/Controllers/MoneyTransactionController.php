<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class MoneyTransactionController extends Controller {
 public function index(Request $request){abort_unless($request->user()->can('administration.view'),403);$type=$request->validate(['type'=>['nullable',Rule::in(['receipt','payment','transfer'])]])['type']??null;return view('finance.money.index',['transactions'=>MoneyTransaction::with(['journal','currency','source','destination'])->when($type,fn($q)=>$q->where('type',$type))->latest()->paginate(25)->withQueryString(),'accounts'=>FinancialAccount::with('currency')->where('is_active',true)->orderBy('name')->get(),'ledgers'=>LedgerAccount::where('is_active',true)->where('is_group',false)->orderBy('code')->get(),'type'=>$type]);}
 public function store(Request $request,JournalPostingService $posting){abort_unless($request->user()->can('administration.update'),403);$data=$request->validate(['type'=>['required',Rule::in(['receipt','payment','transfer'])],'transaction_date'=>['required','date'],'source_account_id'=>['nullable','integer','exists:financial_accounts,id'],'destination_account_id'=>['nullable','integer','exists:financial_accounts,id'],'counterpart_ledger_account_id'=>['nullable','integer','exists:ledger_accounts,id'],'amount'=>['required','numeric','gt:0','max:999999999999.9999'],'reference'=>['nullable','string','max:120'],'description'=>['required','string','max:2000']]);
  DB::transaction(function()use($data,$request,$posting){$type=$data['type'];$source=isset($data['source_account_id'])?FinancialAccount::findOrFail($data['source_account_id']):null;$destination=isset($data['destination_account_id'])?FinancialAccount::findOrFail($data['destination_account_id']):null;
   if(($type==='payment'||$type==='transfer')&&(!$source||!$source->is_active))throw ValidationException::withMessages(['source_account_id'=>'Choose an active source account.']);
   if(($type==='receipt'||$type==='transfer')&&(!$destination||!$destination->is_active))throw ValidationException::withMessages(['destination_account_id'=>'Choose an active destination account.']);
   if($type==='transfer'&&($source->id===$destination->id||$source->currency_id!==$destination->currency_id))throw ValidationException::withMessages(['destination_account_id'=>'Transfers require different accounts in the same currency. Currency exchange is a separate workflow.']);
   $currencyId=$type==='payment'?$source->currency_id:$destination->currency_id;
   $counterpart=null;if($type!=='transfer'){$counterpart=LedgerAccount::find($data['counterpart_ledger_account_id']??null);if(!$counterpart||!$counterpart->is_active||$counterpart->is_group)throw ValidationException::withMessages(['counterpart_ledger_account_id'=>'Select an active non-group counterpart ledger account.']);}
   $lookup=function(FinancialAccount $account)use($currencyId){$linked=DB::table('entity_ledger_accounts')->where(['entity_type'=>$account->type,'entity_id'=>$account->id,'currency_id'=>$currencyId,'purpose'=>'cash'])->first();if(!$linked)throw ValidationException::withMessages(['source_account_id'=>'Account ledger mapping is missing.']);return $linked->ledger_account_id;};
   $debit=$type==='payment'?$counterpart->id:$lookup($destination);$credit=$type==='receipt'?$counterpart->id:$lookup($source);
   if($debit===$credit)throw ValidationException::withMessages(['counterpart_ledger_account_id'=>'Debit and credit accounts must differ.']);
   $sequenceType=$type==='receipt'?'receipt':'payment';DocumentSequence::firstOrCreate(['document_type'=>$sequenceType],['prefix'=>$type==='receipt'?'REC-':'PAY-','next_number'=>1,'padding'=>6,'is_active'=>true]);$number=DocumentSequence::issue($sequenceType);
   $journal=JournalEntry::create(['number'=>$number,'entry_date'=>$data['transaction_date'],'reference'=>$data['reference']??null,'description'=>$data['description'],'currency_id'=>$currencyId,'created_by'=>$request->user()->id,'status'=>'draft']);
   $journal->lines()->create(['ledger_account_id'=>$debit,'debit'=>$data['amount'],'credit'=>0]);$journal->lines()->create(['ledger_account_id'=>$credit,'debit'=>0,'credit'=>$data['amount']]);$posting->post($journal,$request->user()->id);
   $transaction=MoneyTransaction::create(['type'=>$type,'transaction_date'=>$data['transaction_date'],'source_account_id'=>$type==='receipt'?null:$source->id,'destination_account_id'=>$type==='payment'?null:$destination->id,'counterpart_ledger_account_id'=>$counterpart?->id,'currency_id'=>$currencyId,'amount'=>$data['amount'],'reference'=>$data['reference']??null,'description'=>$data['description'],'journal_entry_id'=>$journal->id,'created_by'=>$request->user()->id]);ActivityLog::record('money_transaction.posted','money_transaction',$transaction->id,ucfirst($type).' posted: '.$number);
  });return redirect()->route('finance.money.index')->with('status','Transaction posted to the General Ledger.');}
}
