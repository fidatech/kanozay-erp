<?php
namespace App\Http\Controllers;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class JournalEntryController extends Controller {
 private function guard(Request $request,bool $write=false):void {abort_unless($request->user()->can($write?'administration.update':'administration.view'),403);}
 public function index(Request $request){
  $this->guard($request);
  $journals=JournalEntry::with(['lines','lines.account','currency'])->withCount('lines')->latest('id')->paginate(20);
  $ledgerIds=$journals->getCollection()->flatMap(fn($journal)=>$journal->lines->pluck('ledger_account_id'))->unique()->values();
  $partyLinks=DB::table('entity_ledger_accounts')->whereIn('ledger_account_id',$ledgerIds)->whereIn('entity_type',['customer','supplier'])->get(['ledger_account_id','entity_id','entity_type']);
  $partyNames=\App\Models\TradeParty::whereIn('id',$partyLinks->pluck('entity_id')->unique())->get(['id','type','name'])->keyBy('id');
  $journalAccountLabels=[];
  foreach($partyLinks as $link){$party=$partyNames->get($link->entity_id);if($party&&$party->type===$link->entity_type)$journalAccountLabels[$link->ledger_account_id]=$party->name;}
  return view('finance.journals.index',['journals'=>$journals,'journalAccountLabels'=>$journalAccountLabels,'accounts'=>LedgerAccount::where('is_active',true)->where('is_group',false)->orderBy('code')->get(),'currencies'=>Currency::where('is_active',true)->orderBy('code')->get()]);
 }
 public function store(Request $request){$this->guard($request,true);$data=$request->validate(['entry_date'=>['required','date'],'description'=>['required','string','max:2000'],'reference'=>['nullable','string','max:100'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'lines'=>['required','array','min:2','max:100'],'lines.*.ledger_account_id'=>['required','integer',Rule::exists('ledger_accounts','id')->where('is_active',true)->where('is_group',false)],'lines.*.debit'=>['required','numeric','min:0','max:999999999999.9999'],'lines.*.credit'=>['required','numeric','min:0','max:999999999999.9999'],'lines.*.memo'=>['nullable','string','max:255']]);
  DB::transaction(function()use($data,$request){DocumentSequence::firstOrCreate(['document_type'=>'journal_entry'],['prefix'=>'JE-','next_number'=>1,'padding'=>6,'is_active'=>true]);$number=DocumentSequence::issue('journal_entry');$entry=JournalEntry::create(['number'=>$number,'entry_date'=>$data['entry_date'],'description'=>$data['description'],'reference'=>$data['reference']??null,'currency_id'=>$data['currency_id'],'created_by'=>$request->user()->id,'status'=>'draft']);foreach($data['lines'] as $line)$entry->lines()->create($line);});return back()->with('status','Draft journal created.');}
 public function post(Request $request,JournalEntry $journal,JournalPostingService $service){$this->guard($request,true);$service->post($journal,$request->user()->id);return back()->with('status','Journal posted successfully.');}
 public function reverse(Request $request,JournalEntry $journal,JournalPostingService $service){$this->guard($request,true);$service->reverse($journal,$request->user()->id);return back()->with('status','Reversal posted successfully.');}
}
