<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog;
use App\Services\Accounting\TradeOpeningBalanceService;
use App\Services\Accounting\JournalPostingService;
use App\Models\Currency;
use App\Models\TradeParty;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class TradePartyController extends Controller {
 private function normalizeMoney($value):mixed {
  if(!is_string($value))return $value;
  $value=strtr($value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>',','،'=>',']);
  return str_replace(',','',$value);
 }

 public function updateCustomer(Request $request,TradeParty $party){return $this->updateParty($request,$party,'customer');}
 public function updateSupplier(Request $request,TradeParty $party){return $this->updateParty($request,$party,'supplier');}
 private function updateParty(Request $request,TradeParty $party,string $type){abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);abort_unless($party->type===$type,404);$data=$request->validate(['name'=>['required','string','max:180'],'code'=>['nullable','string','max:40',Rule::unique('trade_parties','code')->where('type',$type)->ignore($party->id)],'contact_person'=>['nullable','string','max:150'],'phone'=>['nullable','string','max:80'],'email'=>['nullable','email','max:180'],'address'=>['nullable','string','max:2000'],'notes'=>['nullable','string','max:2000']]);$before=$party->only(array_keys($data));$party->fill($data);$changed=$party->getDirty();if($changed){DB::transaction(function()use($party,$type,$before,$changed){$party->save();ActivityLog::record('trade_party.updated','trade_party',$party->id,ucfirst($type).' profile updated: '.$party->name,['before'=>array_intersect_key($before,$changed),'after'=>$changed]);});}return redirect()->route($type==='customer'?'finance.customers.show':'finance.suppliers.show',$party)->with('status',$changed?'Profile updated successfully.':'No profile changes detected.');}
 public function showCustomer(Request $request,TradeParty $party){return $this->show($request,$party,'customer');}
 public function showSupplier(Request $request,TradeParty $party){return $this->show($request,$party,'supplier');}
 private function show(Request $request,TradeParty $party,string $type){
  abort_unless($request->user()->canAny(['administration.view','finance.view','reports.view']),403);
  abort_unless($party->type===$type,404);
  $party->load('currencies');
  $documents=\App\Models\TradeDocument::with(['credits','allocations'])
   ->where('trade_party_id',$party->id)->where('type',$type==='customer'?'receivable':'payable')
   ->orderByDesc('id')->limit(100)->get();
  $documents->each(function($document){
   $remaining=BigDecimal::of((string)$document->amount);
   foreach($document->credits as $credit)$remaining=$remaining->minus((string)$credit->amount);
   foreach($document->allocations as $allocation)$remaining=$remaining->minus((string)$allocation->amount);
   if($remaining->isNegative())$remaining=BigDecimal::zero();
   $document->setAttribute('outstanding_amount',(string)$remaining->toScale(4));
  });
  $ledgerLinks=DB::table('entity_ledger_accounts')
   ->where('entity_type',$type)->where('entity_id',$party->id)
   ->whereIn('purpose',['receivable','payable','advance'])->get();
  $ledgerIds=$ledgerLinks->pluck('ledger_account_id')->all();
  $postingTotals=DB::table('journal_lines as line')
   ->join('journal_entries as journal','journal.id','=','line.journal_entry_id')
   ->where('journal.status','posted')->whereIn('line.ledger_account_id',$ledgerIds)
   ->select('journal.currency_id')
   ->selectRaw('SUM(line.debit) AS debit_total, SUM(line.credit) AS credit_total')
   ->groupBy('journal.currency_id')->get()->keyBy('currency_id');
  $accountBalances=[];
  foreach($party->currencies as $currency){
   $row=$postingTotals->get($currency->id);
   $amount=BigDecimal::of((string)($row->debit_total??'0'))
    ->minus((string)($row->credit_total??'0'));
   if($type==='supplier')$amount=$amount->negated();
   $accountBalances[$currency->id]=$amount;
  }
  $recentPayments=\App\Models\MoneyTransaction::with('currency')
   ->whereIn('counterpart_ledger_account_id',$ledgerIds)
   ->whereIn('type',['receipt','payment'])->orderByDesc('id')->limit(15)->get();
  $cashboxes=\App\Models\FinancialAccount::whereIn('type',['cashbox','bank','sarafi'])->where('is_active',true)
   ->whereIn('currency_id',$party->currencies->pluck('id'))->with('currency')->orderBy('name')->get();
  return view('finance.parties.show',[
   'party'=>$party,'type'=>$type,
   'openingBalances'=>DB::table('trade_opening_balances')->where('trade_party_id',$party->id)->get(),
   'documents'=>$documents,'cashboxes'=>$cashboxes,
   'accountBalances'=>$accountBalances,'recentPayments'=>$recentPayments,
  ]);
 }
 public function customers(Request $request){$request->merge(['type'=>'customer']);return $this->index($request);}
 public function suppliers(Request $request){$request->merge(['type'=>'supplier']);return $this->index($request);}
 public function index(Request $request){
  abort_unless($request->user()->canAny(['administration.view','finance.view','reports.view']),403);
  $type=$request->validate(['type'=>['nullable',Rule::in(['customer','supplier'])]])['type']??'customer';
  $parties=TradeParty::with('currencies')->where('type',$type)->when($request->filled('search'),function($query)use($request){
   $term=trim((string)$request->input('search'));
   $query->where(fn($q)=>$q->where('name','like','%'.$term.'%')->orWhere('code','like','%'.$term.'%')->orWhere('phone','like','%'.$term.'%')->orWhere('email','like','%'.$term.'%'));
  })->latest()->paginate(25)->withQueryString();
  $ids=$parties->getCollection()->pluck('id')->all();
  $links=DB::table('entity_ledger_accounts')->where('entity_type',$type)->whereIn('entity_id',$ids)
   ->whereIn('purpose',['receivable','payable','advance'])->get(['entity_id','currency_id','ledger_account_id']);
  $posted=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')
   ->where('j.status','posted')->whereIn('l.ledger_account_id',$links->pluck('ledger_account_id')->all())
   ->select('l.ledger_account_id','j.currency_id')->selectRaw('SUM(l.debit) as debit_total, SUM(l.credit) as credit_total')
   ->groupBy('l.ledger_account_id','j.currency_id')->get()->keyBy(fn($r)=>$r->ledger_account_id.':'.$r->currency_id);
  $partyBalances=[];
  foreach($links as $link){
   $row=$posted->get($link->ledger_account_id.':'.$link->currency_id);
   $movement=BigDecimal::of((string)($row->debit_total??'0'))->minus((string)($row->credit_total??'0'));
   if($type==='supplier')$movement=$movement->negated();
   $partyBalances[$link->entity_id][$link->currency_id]=($partyBalances[$link->entity_id][$link->currency_id]??BigDecimal::zero())->plus($movement);
  }
  return view('finance.parties.index',[
   'type'=>$type,'parties'=>$parties,'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
   'partyBalances'=>$partyBalances,
  ]);
 }
 public function statement(Request $request,TradeParty $party){
  abort_unless($request->user()->canAny(['administration.view','finance.view','reports.view']),403);
  $filters=$request->validate([
   'currency_id'=>['nullable','integer','exists:currencies,id'],
   'from'=>['nullable','date'],
   'to'=>['nullable','date','after_or_equal:from'],
  ]);
  $party->load('currencies');
  if(!empty($filters['currency_id']) && !$party->currencies->contains('id',(int)$filters['currency_id'])){
   throw ValidationException::withMessages(['currency_id'=>'Choose a currency enabled for this account.']);
  }
  $links=DB::table('entity_ledger_accounts')->where('entity_type',$party->type)->where('entity_id',$party->id)
   ->whereIn('purpose',['receivable','payable','advance'])->get();
  $query=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')
   ->where('j.status','posted')->whereIn('l.ledger_account_id',$links->pluck('ledger_account_id')->all());
  if(!empty($filters['currency_id']))$query->where('j.currency_id',$filters['currency_id']);
  $opening=[];$running=[];$rows=[];
  if(!empty($filters['from'])){
   $prior=(clone $query)->where('j.entry_date','<',$filters['from'])
    ->select('j.currency_id')->selectRaw('SUM(l.debit) as debit_total,SUM(l.credit) as credit_total')
    ->groupBy('j.currency_id')->get();
   foreach($prior as $item){
    $amount=BigDecimal::of((string)$item->debit_total)->minus((string)$item->credit_total);
    if($party->type==='supplier')$amount=$amount->negated();
    $opening[$item->currency_id]=$amount;
    $running[$item->currency_id]=$amount;
   }
   $query->where('j.entry_date','>=',$filters['from']);
  }
  if(!empty($filters['to']))$query->where('j.entry_date','<=',$filters['to']);
  $entries=$query->select('l.id','l.ledger_account_id','l.debit','l.credit','j.number','j.entry_date','j.description','j.currency_id')
   ->orderBy('j.entry_date')->orderBy('j.id')->orderBy('l.id')->get();
  $currencies=Currency::whereIn('id',$party->currencies->pluck('id'))->get()->keyBy('id');
  foreach($entries as $entry){
   $id=(int)$entry->currency_id;
   $delta=BigDecimal::of((string)$entry->debit)->minus((string)$entry->credit);
   if($party->type==='supplier')$delta=$delta->negated();
   $running[$id]=($running[$id]??BigDecimal::zero())->plus($delta);
   $rows[]=['entry'=>$entry,'movement'=>$delta,'balance'=>$running[$id]];
  }
  if($request->routeIs('finance.parties.statement.export')){
   $safeName=preg_replace('/[^A-Za-z0-9_-]+/','-',strtolower($party->name)) ?: 'account';
   $filename=$party->type.'-'.$safeName.'-statement.csv';
   return response()->streamDownload(function()use($party,$filters,$currencies,$opening,$running,$rows){
    $out=fopen('php://output','w');
    // Prefix dynamic text fields with a quote to prevent spreadsheet formula execution.
    $safe=static function($value):string{
     $value=(string)$value;
     return preg_match('/^[\s]*[=+\-@]/u',$value)?"'".$value:$value;
    };
    fputcsv($out,['Account',$safe($party->name)]);
    fputcsv($out,['Type',ucfirst($party->type)]);
    fputcsv($out,['From',$filters['from']??'Beginning']);
    fputcsv($out,['To',$filters['to']??'Latest']);
    fputcsv($out,[]);
    fputcsv($out,['Date','Journal','Description','Currency','Debit','Credit','Running Balance']);
    foreach($rows as $row){
     $entry=$row['entry'];
     fputcsv($out,[
      $entry->entry_date,$safe($entry->number),$safe($entry->description),
      $currencies[$entry->currency_id]->code??'',
      (string)$entry->debit,(string)$entry->credit,(string)$row['balance'],
     ]);
    }
    fputcsv($out,[]);
    fputcsv($out,['Currency','Opening Balance','Closing Balance']);
    foreach($currencies as $currency){
     if(!empty($filters['currency_id']) && (int)$filters['currency_id']!==$currency->id)continue;
     fputcsv($out,[$currency->code,(string)($opening[$currency->id]??BigDecimal::zero())->toScale(4),(string)($running[$currency->id]??BigDecimal::zero())->toScale(4)]);
    }
    fclose($out);
   },$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
  }
  return view('finance.parties.statement',compact('party','currencies','rows','running','opening','filters'));
 }
 public function destroy(Request $request,TradeParty $party){
  abort_unless($request->user()->canAny(['administration.update','finance.update']),403);
  DB::transaction(function()use($party){
   $party=TradeParty::whereKey($party->id)->lockForUpdate()->firstOrFail();
   $links=DB::table('entity_ledger_accounts')->where('entity_type',$party->type)->where('entity_id',$party->id)->get();
   if(DB::table('trade_documents')->where('trade_party_id',$party->id)->exists()
    || DB::table('trade_opening_balances')->where('trade_party_id',$party->id)->exists()
    || DB::table('reconciliation_cases')->where('trade_party_id',$party->id)->exists()
    || DB::table('journal_lines')->whereIn('ledger_account_id',$links->pluck('ledger_account_id'))->exists()
    || DB::table('money_transactions')->whereIn('counterpart_ledger_account_id',$links->pluck('ledger_account_id'))->exists()){
    throw ValidationException::withMessages(['party'=>'This account has financial history and cannot be deleted.']);
   }
   try{
    foreach($links as $link){
     DB::table('entity_ledger_accounts')->where('entity_type',$party->type)->where('entity_id',$party->id)
      ->where('currency_id',$link->currency_id)->where('purpose',$link->purpose)->delete();
     \App\Models\LedgerAccount::whereKey($link->ledger_account_id)->firstOrFail()->delete();
    }
    $party->currencies()->detach();
    $party->delete();
   }catch(QueryException $e){
    throw ValidationException::withMessages(['party'=>'This account is referenced elsewhere and cannot be deleted.']);
   }
   ActivityLog::record('trade_party.deleted','trade_party',$party->id,'Unused '.$party->type.' deleted: '.$party->name);
  });
  return redirect()->route($party->type==='customer'?'finance.customers.index':'finance.suppliers.index')->with('status','Unused account deleted.');
 }
 public function store(Request $request,TradeOpeningBalanceService $opening,JournalPostingService $posting){abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);if($request->has('opening_balances')){$request->merge(['opening_balances'=>array_map(fn($v)=>$this->normalizeMoney($v),$request->input('opening_balances',[]))]);}$data=$request->validate(['type'=>['required',Rule::in(['customer','supplier'])],'name'=>['required','string','max:180'],'code'=>['nullable','string','max:40',Rule::unique('trade_parties','code')->where('type',$request->input('type'))],'contact_person'=>['nullable','string','max:150'],'phone'=>['nullable','string','max:80'],'email'=>['nullable','email','max:180'],'address'=>['nullable','string','max:2000'],'notes'=>['nullable','string','max:2000'],'currency_ids'=>['sometimes','array','min:1'],'currency_ids.*'=>['required','integer','distinct',Rule::exists('currencies','id')->where('is_active',true)],'opening_date'=>['nullable','required_with:opening_balances','date'],'opening_balances'=>['nullable','array'],'opening_balances.*'=>['nullable','numeric','decimal:0,4','between:0,999999999999.9999'],'opening_directions'=>['nullable','array'],'opening_directions.*'=>['nullable',Rule::in(['they_owe_us','we_owe_them'])]]);DB::transaction(function()use($data,$request,$opening,$posting){$openingAmounts=$data['opening_balances']??[];$openingDirections=$data['opening_directions']??[];$openingDate=$data['opening_date']??now()->toDateString();unset($data['opening_balances'],$data['opening_directions'],$data['opening_date']);$currencyIds=$data['currency_ids']??Currency::where('is_active',true)->pluck('id')->all();unset($data['currency_ids']);if(!$currencyIds)throw ValidationException::withMessages(['name'=>'Configure an active currency before adding a customer or supplier.']);$party=TradeParty::create($data+['is_active'=>true,'created_by'=>$request->user()->id]);$party->currencies()->sync($currencyIds);foreach($currencyIds as $id)$party->provisionLedgerAccountsForCurrency((int)$id);foreach($openingAmounts as $currencyId=>$amount){if($amount===null||$amount==='')continue;if(!in_array((int)$currencyId,array_map('intval',$currencyIds),true))throw ValidationException::withMessages(['opening_balances'=>'Opening balance currency must be selected.']);$direction=$openingDirections[$currencyId]??($party->type==='customer'?'they_owe_us':'we_owe_them');$signed=$direction===($party->type==='customer'?'they_owe_us':'we_owe_them')?(string)$amount:'-'.(string)$amount;$opening->post($party,(int)$currencyId,$signed,$openingDate,$request->user()->id,$posting);}ActivityLog::record('trade_party.created','trade_party',$party->id,ucfirst($party->type).' created: '.$party->name);});return redirect()->route($data['type']==='customer'?'finance.customers.index':'finance.suppliers.index')->with('status','Trade party and currency-specific ledger accounts created.');}
 public function addCurrency(Request $request,TradeParty $party){abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);$data=$request->validate(['currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)->where('is_active',true)]]);DB::transaction(function()use($party,$data){if($party->currencies()->whereKey($data['currency_id'])->exists())throw ValidationException::withMessages(['currency_id'=>'Currency already enabled.']);$party->provisionLedgerAccountsForCurrency((int)$data['currency_id']);$party->currencies()->attach($data['currency_id']);ActivityLog::record('trade_party.currency_added','trade_party',$party->id,'Currency enabled for '.$party->name);});return back()->with('status','Currency and ledger accounts enabled.');}
 public function openingBalance(Request $request,TradeParty $party,TradeOpeningBalanceService $opening,JournalPostingService $posting){abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);$request->merge(['amount'=>$this->normalizeMoney($request->input('amount'))]);$data=$request->validate(['currency_id'=>['required','integer','exists:currencies,id'],'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],'direction'=>['required',Rule::in(['they_owe_us','we_owe_them'])],'balance_date'=>['required','date']]);DB::transaction(function()use($party,$data,$opening,$posting,$request){$signed=$data['direction']===($party->type==='customer'?'they_owe_us':'we_owe_them')?(string)$data['amount']:'-'.(string)$data['amount'];$opening->post($party,(int)$data['currency_id'],$signed,$data['balance_date'],$request->user()->id,$posting);ActivityLog::record('trade_party.opening_balance','trade_party',$party->id,'Opening balance posted for '.$party->name);});return back()->with('status','Opening balance posted successfully.');}

}
