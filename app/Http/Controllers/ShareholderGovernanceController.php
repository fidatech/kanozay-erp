<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Shareholder;
use App\Models\DocumentSequence;
use App\Models\MoneyTransaction;
use App\Services\ShareholderDistributionAllocationService;
use App\Services\FinancePeriodClosingService;
use App\Support\LocalizedDecimal;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShareholderGovernanceController extends Controller {
 private const PAGES=['ownership','resolutions','distributions'];
 private function check(Request $request,bool $edit=false):void {
  abort_unless($request->user()->canAny($edit?['shareholders.update','administration.update']:
   ['shareholders.view','administration.view']),403);
 }
 public function index(Request $request,string $page){
  abort_unless(in_array($page,self::PAGES,true),404);$this->check($request);
  $filters=$request->validate(['as_of'=>['nullable','date','before_or_equal:today']]);
  $asOf=$filters['as_of']??today()->toDateString();
  // These are actual board-approved ownership event values, not a derived
  // inference from historic capital contributions or journal balances.
  $history=DB::table('shareholder_ownership_changes as change')
    ->join('shareholder_ownership_records as current',
      'current.id','=','change.ownership_record_id')
    ->join('shareholders as owner','owner.id','=','current.shareholder_id')
    ->whereDate('change.effective_on','<=',$asOf)
    ->orderBy('change.ownership_record_id')
    ->orderByDesc('change.effective_on')->orderByDesc('change.id')
    ->select('change.*','current.share_class','owner.name as shareholder_name')
    ->get()->unique('ownership_record_id')->values();
  $ownershipSnapshot=$history->filter(fn($row)=>BigDecimal::of((string)$row->new_percentage)->isPositive());
  $classTotals=[];
  foreach($ownershipSnapshot as $row){
    $code=mb_strtoupper($row->share_class);
    $classTotals[$code]=isset($classTotals[$code])
      ?$classTotals[$code]->plus((string)$row->new_percentage)
      :BigDecimal::of((string)$row->new_percentage);
  }
  foreach($classTotals as $class=>$total)$classTotals[$class]=(string)$total->toScale(4);
  $allocations=DB::table('shareholder_distribution_allocations as a')
    ->join('shareholders as s','s.id','=','a.shareholder_id')
    ->join('shareholder_resolutions as r','r.id','=','a.shareholder_resolution_id')
    ->leftJoin('currencies as c','c.id','=','r.currency_id')
    ->select('a.*','s.name as shareholder_name','r.number as resolution_number',
      'r.status as resolution_status','c.code as currency_code')
    ->orderByDesc('a.id')->limit(300)->get();
  $approvedAllocationOptions=$allocations->where('resolution_status','approved')
    ->map(fn($row)=>[
      'resolution'=>(int)$row->shareholder_resolution_id,
      'shareholder'=>(int)$row->shareholder_id,
    ])->values()->all();
  return view('shareholders.governance',[
   'approvedAllocationOptions'=>$approvedAllocationOptions,
   'ownershipAsOf'=>$asOf,
   'ownershipSnapshot'=>$ownershipSnapshot,
   'ownershipClassTotals'=>$classTotals,
   'page'=>$page,'pageTitle'=>config('erp_pages.shareholders.pages.'.$page),
   'shareholders'=>Shareholder::where('is_active',true)->orderBy('name')->get(),
   'ownership'=>DB::table('shareholder_ownership_records as o')
    ->join('shareholders as s','s.id','=','o.shareholder_id')
    ->select('o.*','s.name as shareholder_name')->orderBy('o.share_class')->orderBy('s.name')->get(),
   'changes'=>DB::table('shareholder_ownership_changes')->orderByDesc('id')->limit(100)->get(),
   'resolutions'=>DB::table('shareholder_resolutions')->orderByDesc('id')->get(),
   'allocations'=>$allocations,
   'distributions'=>DB::table('shareholder_profit_distributions as d')
    ->join('shareholders as s','s.id','=','d.shareholder_id')
    ->join('shareholder_resolutions as r','r.id','=','d.shareholder_resolution_id')
    ->join('currencies as c','c.id','=','d.currency_id')
    ->select('d.*','s.name as shareholder_name','r.number as resolution_number','c.code as currency_code')
    ->orderByDesc('d.id')->limit(200)->get(),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
   'accounts'=>FinancialAccount::where('is_active',true)->orderBy('name')->get(),
  ]);
 }
 public function ownership(Request $request){
  $this->check($request,true);
  $request->merge(['percentage'=>LocalizedDecimal::normalize($request->input('percentage'))]);
  $data=$request->validate([
   'shareholder_id'=>['required','integer',Rule::exists('shareholders','id')->where('is_active',true)],
   'share_class'=>['required','string','max:60','regex:/^[A-Za-z0-9 _-]+$/'],
   'percentage'=>['required','numeric','gte:0','lte:100','decimal:0,4'],
   'effective_on'=>['required','date','before_or_equal:today'],
   'approval_reference'=>['required','string','min:4','max:180'],
  ]);
  DB::transaction(function()use($request,$data){
   $shareClass=trim($data['share_class']);
   // Serialize cap table amendments to avoid concurrent allocations exceeding 100%.
   DB::table('shareholders')->where('id','>=',0)->orderBy('id')->lockForUpdate()->get();
   // Share class names are case-insensitive for ownership arithmetic.
   // Reject retroactive class amendments after later documented board events.
   $classKey=mb_strtolower($shareClass);
   $latestAmendment=DB::table('shareholder_ownership_changes as change')
    ->join('shareholder_ownership_records as current',
      'current.id','=','change.ownership_record_id')
    ->whereRaw('LOWER(current.share_class) = ?',[$classKey])
    ->max('change.effective_on');
   if($latestAmendment && $data['effective_on']<$latestAmendment){
    throw ValidationException::withMessages([
     'effective_on'=>'Ownership amendments within a share class must be recorded chronologically. Enter a current effective date.',
    ]);
   }
   $existing=DB::table('shareholder_ownership_records')
    ->where('shareholder_id',$data['shareholder_id'])
    ->whereRaw('LOWER(share_class) = ?',[$classKey])->first();
   $total=BigDecimal::zero();
   foreach(DB::table('shareholder_ownership_records')
     ->whereRaw('LOWER(share_class) = ?',[$classKey])->get() as $record){
    if($existing && $record->id===$existing->id)continue;
    $total=$total->plus((string)$record->percentage);
   }
   $new=BigDecimal::of((string)$data['percentage'])->toScale(4);
   if($total->plus($new)->isGreaterThan('100'))
    throw ValidationException::withMessages(['percentage'=>'Ownership within a share class cannot exceed 100%.']);
   if($existing && $data['effective_on']<$existing->effective_on)
    throw ValidationException::withMessages(['effective_on'=>'Ownership amendments must not be backdated before the last record.']);
   if($existing){
    DB::table('shareholder_ownership_records')->where('id',$existing->id)->update([
     'percentage'=>(string)$new,'effective_on'=>$data['effective_on'],
     'approval_reference'=>$data['approval_reference'],'updated_by'=>$request->user()->id,'updated_at'=>now(),
    ]);
    $id=$existing->id;
   }else{
    $id=DB::table('shareholder_ownership_records')->insertGetId([
     'shareholder_id'=>$data['shareholder_id'],'share_class'=>$shareClass,
     'percentage'=>(string)$new,'approval_reference'=>$data['approval_reference'],
     'effective_on'=>$data['effective_on'],'updated_by'=>$request->user()->id,
     'created_at'=>now(),'updated_at'=>now(),
    ]);
   }
   DB::table('shareholder_ownership_changes')->insert([
    'ownership_record_id'=>$id,'old_percentage'=>$existing?->percentage??'0',
    'new_percentage'=>(string)$new,'approval_reference'=>$data['approval_reference'],
    'effective_on'=>$data['effective_on'],'recorded_by'=>$request->user()->id,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('shareholder.ownership.amended','shareholder',$data['shareholder_id'],
    'Board-referenced ownership entry saved without inferring capital ownership.');
  });
  return back()->with('success','Ownership register updated with full history.');
 }
 public function resolution(Request $request){
  $this->check($request,true);
  $request->merge(['distribution_cap'=>LocalizedDecimal::normalize($request->input('distribution_cap'))]);
  $data=$request->validate([
   'number'=>['required','string','max:45','regex:/^[A-Za-z0-9_\\/-]+$/','unique:shareholder_resolutions,number'],
   'title'=>['required','string','max:180'],
   'purpose'=>['required',Rule::in(['distribution','governance'])],
   'currency_id'=>['nullable','required_if:purpose,distribution',Rule::exists('currencies','id')->where('is_active',true)],
   'distribution_cap'=>['nullable','required_if:purpose,distribution','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
   'resolution_on'=>['required','date','before_or_equal:today'],
   'document_reference'=>['required','string','min:4','max:180'],
   'notes'=>['nullable','string','max:5000'],
  ]);
  DB::transaction(function()use($request,$data){
   $id=DB::table('shareholder_resolutions')->insertGetId([
    'number'=>$data['number'],'title'=>$data['title'],'purpose'=>$data['purpose'],
    'currency_id'=>$data['purpose']==='distribution'?($data['currency_id']??null):null,
    'distribution_cap'=>$data['purpose']==='distribution'?($data['distribution_cap']??null):null,
    'resolution_on'=>$data['resolution_on'],'document_reference'=>$data['document_reference'],
    'status'=>'draft','notes'=>$data['notes']??null,
    'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('shareholder.resolution.created','shareholder_resolution',$id,
    'Board resolution draft registered with source document reference.');
  });
  return redirect()->route('workspace.page',['shareholders','resolutions'])
   ->with('success','Resolution draft created for independent approval.');
 }
 public function decide(Request $request,int $resolution){
  $this->check($request,true);
  $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])]]);
  DB::transaction(function()use($request,$resolution,$data){
   $item=DB::table('shareholder_resolutions')->where('id',$resolution)->lockForUpdate()->first();
   abort_unless($item,404);
   if($item->status!=='draft')throw ValidationException::withMessages(['decision'=>'Decision already recorded.']);
   if((int)$item->created_by===$request->user()->id)
    throw ValidationException::withMessages(['decision'=>'An independent reviewer must approve or reject the board resolution.']);
   if($data['decision']==='approved'){
    app(ShareholderDistributionAllocationService::class)->assertReadyForApproval($item,$request->user()->id);
   }
   DB::table('shareholder_resolutions')->where('id',$resolution)->update([
    'status'=>$data['decision'],'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('shareholder.resolution.'.$data['decision'],'shareholder_resolution',$resolution,
    'Board resolution independently reviewed.');
  });
  return back()->with('success','Resolution review recorded.');
 }
 public function allocate(Request $request,ShareholderDistributionAllocationService $service){
  $this->check($request,true);
  $request->merge([
   'authorized_amount'=>LocalizedDecimal::normalize($request->input('authorized_amount')),
  ]);
  $data=$request->validate([
   'shareholder_resolution_id'=>['required','integer',
     Rule::exists('shareholder_resolutions','id')->where('purpose','distribution')->where('status','draft')],
   'shareholder_id'=>['required','integer',Rule::exists('shareholders','id')->where('is_active',true)],
   'authorized_amount'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
   'approval_reference'=>['required','string','min:4','max:180'],
  ]);
  $service->save($data,$request->user()->id);
  return redirect()->route('workspace.page',['shareholders','resolutions'])
    ->with('success','Per-shareholder distribution entitlement documented. Independent resolution approval is still required.');
 }

 public function distribute(Request $request,JournalPostingService $posting){
  $this->check($request,true);
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $request->merge(['amount'=>LocalizedDecimal::normalize($request->input('amount'))]);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'shareholder_resolution_id'=>['required','integer',Rule::exists('shareholder_resolutions','id')->where('purpose','distribution')->where('status','approved')],
   'shareholder_id'=>['required','integer',Rule::exists('shareholders','id')->where('is_active',true)],
   'financial_account_id'=>['required','integer',Rule::exists('financial_accounts','id')->where('is_active',true)],
   'paid_on'=>['required','date','before_or_equal:today'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
  ]);
  DB::transaction(function()use($request,$data,$posting){
   // Serialize payout availability and period closure against all other GL postings.
   app(FinancePeriodClosingService::class)->holdPostingGate();
   $duplicate=DB::table('shareholder_profit_distributions')
    ->where('submission_token',$data['submission_token'])->first();
   if($duplicate){
    if((int)$duplicate->shareholder_resolution_id===(int)$data['shareholder_resolution_id'] &&
       (int)$duplicate->shareholder_id===(int)$data['shareholder_id'] &&
       (int)$duplicate->financial_account_id===(int)$data['financial_account_id'] &&
       $duplicate->paid_on===$data['paid_on'] &&
       BigDecimal::of((string)$duplicate->amount)->isEqualTo((string)$data['amount']))return;
    throw ValidationException::withMessages([
     'submission_token'=>'This distribution token was already used for a different payout.',
    ]);
   }
   $resolution=DB::table('shareholder_resolutions')->where('id',$data['shareholder_resolution_id'])
    ->lockForUpdate()->first();
   abort_unless($resolution,404);
   if($resolution->status!=='approved'||$resolution->purpose!=='distribution')
    throw ValidationException::withMessages(['shareholder_resolution_id'=>'Choose an approved distribution resolution.']);
   if($data['paid_on']<$resolution->resolution_on)
    throw ValidationException::withMessages(['paid_on'=>'Distribution date cannot precede the resolution date.']);
   $party=Shareholder::whereKey($data['shareholder_id'])->lockForUpdate()->firstOrFail();
   if(!$party->is_active||!$party->currencies()->whereKey($resolution->currency_id)->exists())
    throw ValidationException::withMessages(['shareholder_id'=>'Shareholder does not have the distribution currency enabled.']);
   $account=FinancialAccount::whereKey($data['financial_account_id'])->lockForUpdate()->firstOrFail();
   if(!$account->is_active||$account->currency_id!==$resolution->currency_id)
    throw ValidationException::withMessages(['financial_account_id'=>'Payout financial-account currency mismatch.']);
   $cashLedger=DB::table('entity_ledger_accounts')->where([
    'entity_type'=>$account->type,'entity_id'=>$account->id,
    'currency_id'=>$resolution->currency_id,'purpose'=>'cash',
   ])->value('ledger_account_id');
   if(!$cashLedger)throw ValidationException::withMessages(['financial_account_id'=>'Financial account cash ledger is missing.']);
   $retained=LedgerAccount::where('code','3200')->where('type','equity')
    ->where('is_group',false)->where('is_active',true)->first();
   if(!$retained)throw ValidationException::withMessages(['account'=>'Configure the non-group retained-earnings account 3200 first.']);
   $amount=BigDecimal::of((string)$data['amount'])->toScale(4);
   $allocation=DB::table('shareholder_distribution_allocations')
     ->where('shareholder_resolution_id',$resolution->id)
     ->where('shareholder_id',$party->id)->first();
   if(!$allocation){
    throw ValidationException::withMessages([
     'shareholder_id'=>'This shareholder has no explicitly approved entitlement in the signed distribution resolution. Existing legacy resolutions require a new approved allocation resolution.',
    ]);
   }
   $paidForPayee=BigDecimal::zero();
   foreach(DB::table('shareholder_profit_distributions')
      ->where('shareholder_resolution_id',$resolution->id)
      ->where('shareholder_id',$party->id)->pluck('amount') as $paidAmount){
    $paidForPayee=$paidForPayee->plus((string)$paidAmount);
   }
   if($paidForPayee->plus($amount)->isGreaterThan((string)$allocation->authorized_amount)){
    throw ValidationException::withMessages([
     'amount'=>'Shareholder payout exceeds their individually approved distribution entitlement.',
    ]);
   }
   $already=BigDecimal::zero();
   foreach(DB::table('shareholder_profit_distributions')
     ->where('shareholder_resolution_id',$resolution->id)->pluck('amount') as $a)$already=$already->plus((string)$a);
   if($already->plus($amount)->isGreaterThan((string)$resolution->distribution_cap))
    throw ValidationException::withMessages(['amount'=>'Total payouts exceed the approved resolution distribution cap.']);
   $earned=BigDecimal::zero();
   $items=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')
     ->where('j.status','posted')->where('j.currency_id',$resolution->currency_id)
     ->whereDate('j.entry_date','<=',$data['paid_on'])
     ->where('l.ledger_account_id',$retained->id)->select('l.debit','l.credit')->get();
   foreach($items as $i)$earned=$earned->plus((string)$i->credit)->minus((string)$i->debit);
   if($earned->isLessThan($amount))
    throw ValidationException::withMessages(['amount'=>'Posted retained earnings in this currency are insufficient for the distribution. No estimated profits may be paid.']);
   DocumentSequence::firstOrCreate(['document_type'=>'shareholder_distribution'],
     ['prefix'=>'SHD-','next_number'=>1,'padding'=>7,'is_active'=>true]);
   $number=DocumentSequence::issue('shareholder_distribution');
   $journal=JournalEntry::create([
    'number'=>'JE-'.$number,
    'entry_date'=>$data['paid_on'],'reference'=>$resolution->number,
    'description'=>'Approved shareholder profit distribution '.$resolution->number,
    'status'=>'draft','currency_id'=>$resolution->currency_id,'created_by'=>$request->user()->id,
   ]);
   $journal->lines()->createMany([
    ['ledger_account_id'=>$retained->id,'debit'=>(string)$amount,'credit'=>'0'],
    ['ledger_account_id'=>$cashLedger,'debit'=>'0','credit'=>(string)$amount],
   ]);
   $posting->post($journal,$request->user()->id);
   // Ensure cashbook and bank statement reconciliation see the payout.
   MoneyTransaction::create([
    'submission_token'=>$data['submission_token'],
    'type'=>'payment','transaction_date'=>$data['paid_on'],
    'source_account_id'=>$account->id,'destination_account_id'=>null,
    'counterpart_ledger_account_id'=>$retained->id,
    'currency_id'=>$resolution->currency_id,
    'amount'=>(string)$amount,'reference'=>$resolution->number,
    'description'=>'Shareholder distribution '.$resolution->number.' / '.$party->name,
    'journal_entry_id'=>$journal->id,'created_by'=>$request->user()->id,
   ]);
   $id=DB::table('shareholder_profit_distributions')->insertGetId([
    'submission_token'=>$data['submission_token'],'shareholder_resolution_id'=>$resolution->id,
    'shareholder_id'=>$party->id,'currency_id'=>$resolution->currency_id,
    'financial_account_id'=>$account->id,'amount'=>(string)$amount,'paid_on'=>$data['paid_on'],
    'journal_entry_id'=>$journal->id,'created_by'=>$request->user()->id,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('shareholder.distribution.posted','shareholder_profit_distribution',$id,
    'Approved distribution posted against actual retained earnings in the same currency.');
  });
  return redirect()->route('workspace.page',['shareholders','distributions'])
    ->with('success','Approved shareholder profit distribution posted to the GL.');
 }
}
