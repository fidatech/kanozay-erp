<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog;
use App\Models\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\FinancialAccount;
use App\Models\MoneyTransaction;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class JournalEntryController extends Controller {
 private function guard(Request $request,bool $write=false):void {abort_unless($request->user()->canAny($write ? ['administration.update', 'finance.update'] : ['administration.view', 'finance.view', 'finance.update', 'reports.view']),403);}
 public function index(Request $request){
  $this->guard($request);
  $journals=JournalEntry::with(['lines','lines.account','currency'])->withCount('lines')->latest('id')->paginate(20);
  $reversedIds=JournalEntry::whereIn('reversal_of_id',$journals->getCollection()->pluck('id'))->whereNotNull('reversal_of_id')->pluck('reversal_of_id')->all();
  $ledgerIds=$journals->getCollection()->flatMap(fn($journal)=>$journal->lines->pluck('ledger_account_id'))->unique()->values();
  $partyLinks=DB::table('entity_ledger_accounts')->whereIn('ledger_account_id',$ledgerIds)->whereIn('entity_type',['customer','supplier'])->get(['ledger_account_id','entity_id','entity_type']);
  $partyNames=\App\Models\TradeParty::whereIn('id',$partyLinks->pluck('entity_id')->unique())->get(['id','type','name'])->keyBy('id');
  $journalAccountLabels=[];
  foreach($partyLinks as $link){$party=$partyNames->get($link->entity_id);if($party&&$party->type===$link->entity_type)$journalAccountLabels[$link->ledger_account_id]=$party->name;}
  $transactionFilter=$request->validate(['tx_type'=>['nullable',Rule::in(['receipt','payment'])]])['tx_type']??null;
  $transactions=MoneyTransaction::with(['journal','currency','source','destination'])->when($transactionFilter,fn($query,$type)=>$query->where('type',$type))
    ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20,['*'],'transactions_page')->withQueryString();
  $counterparts=DB::table('entity_ledger_accounts')->whereIn('ledger_account_id',$transactions->getCollection()->pluck('counterpart_ledger_account_id')->filter())
    ->whereIn('entity_type',['customer','supplier'])->get(['ledger_account_id','entity_id','entity_type']);
  $partiesById=TradeParty::whereIn('id',$counterparts->pluck('entity_id'))->get()->keyBy('id');
  $transactionPartyNames=[];
  foreach($counterparts as $link){$party=$partiesById->get($link->entity_id);if($party && $party->type===$link->entity_type)$transactionPartyNames[$link->ledger_account_id]=$party->name;}
  $quickParties=TradeParty::with('currencies:id')->where('is_active',true)->whereIn('type',['customer','supplier'])->orderBy('name')->get(['id','name','type']);
  $quickCashboxes=FinancialAccount::where('type','cashbox')->where('is_active',true)->orderBy('name')->get(['id','name','currency_id']);
  $quickFinancials=FinancialAccount::where('is_active',true)->orderBy('name')->get(['id','name','type','currency_id']);
  $mappedLedgerIds=DB::table('entity_ledger_accounts')->pluck('ledger_account_id');
  $quickGeneralLedgers=LedgerAccount::where('is_active',true)->where('is_group',false)
    ->whereNotIn('id',$mappedLedgerIds)->orderBy('code')->get(['id','code','name','type']);
  return view('finance.journals.index',['transactions'=>$transactions,'transactionPartyNames'=>$transactionPartyNames,'quickParties'=>$quickParties,'quickCashboxes'=>$quickCashboxes,'quickFinancials'=>$quickFinancials,'quickGeneralLedgers'=>$quickGeneralLedgers,'journals'=>$journals,'reversedIds'=>$reversedIds,'journalAccountLabels'=>$journalAccountLabels,'accounts'=>LedgerAccount::where('is_active',true)->where('is_group',false)->orderBy('code')->get(),'currencies'=>Currency::where('is_active',true)->orderBy('code')->get()]);
 }
 public function store(Request $request){$this->guard($request,true);$data=$request->validate(['entry_date'=>['required','date'],'description'=>['required','string','max:2000'],'reference'=>['nullable','string','max:100'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'lines'=>['required','array','min:2','max:100'],'lines.*.ledger_account_id'=>['required','integer',Rule::exists('ledger_accounts','id')->where('is_active',true)->where('is_group',false)],'lines.*.debit'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],'lines.*.credit'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],'lines.*.memo'=>['nullable','string','max:255']]);
  $debits=BigDecimal::zero();$credits=BigDecimal::zero();
  foreach($data['lines'] as $i=>$line){
   $debit=BigDecimal::of((string)$line['debit']);$credit=BigDecimal::of((string)$line['credit']);
   if(($debit->isPositive()&&$credit->isPositive())||($debit->isZero()&&$credit->isZero()))
    throw ValidationException::withMessages(['lines.'.$i.'.debit'=>'Each journal line must contain either a positive debit or a positive credit.']);
   $debits=$debits->plus($debit);$credits=$credits->plus($credit);
  }
  if(!$debits->isPositive() || $debits->compareTo($credits)!==0)
   throw ValidationException::withMessages(['lines'=>'Total debits must equal total credits before a draft can be saved.']);
  DB::transaction(function()use($data,$request){DocumentSequence::firstOrCreate(['document_type'=>'journal_entry'],['prefix'=>'JE-','next_number'=>1,'padding'=>6,'is_active'=>true]);$number=DocumentSequence::issue('journal_entry');$entry=JournalEntry::create(['number'=>$number,'entry_date'=>$data['entry_date'],'description'=>$data['description'],'reference'=>$data['reference']??null,'currency_id'=>$data['currency_id'],'created_by'=>$request->user()->id,'status'=>'draft']);foreach($data['lines'] as $line)$entry->lines()->create($line);ActivityLog::record('journal.created','journal_entry',$entry->id,'Draft journal '.$entry->number.' created');});return back()->with('status','Draft journal created.');}
 public function post(Request $request,JournalEntry $journal,JournalPostingService $service){$this->guard($request,true);$service->post($journal,$request->user()->id);return back()->with('status','Journal posted successfully.');}
 public function reverse(Request $request,JournalEntry $journal,JournalPostingService $service){$this->guard($request,true);$service->reverse($journal,$request->user()->id);return back()->with('status','Reversal posted successfully.');}
}
