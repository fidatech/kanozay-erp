<?php
namespace App\Services\Accounting;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\TradeParty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TradeOpeningBalanceService {
 public function post(TradeParty $party,int $currencyId,string $amount,string $date,int $userId,JournalPostingService $posting):void {
  if(bccomp($amount,'0',4)===0)return;
  if(DB::table('trade_opening_balances')->where(['trade_party_id'=>$party->id,'currency_id'=>$currencyId])->exists())throw ValidationException::withMessages(['opening_balances'=>'Opening balance already recorded for this party and currency.']);
  if(!$party->currencies()->whereKey($currencyId)->exists())throw ValidationException::withMessages(['opening_balances'=>'Currency is not enabled for this party.']);
  $positive=bccomp($amount,'0',4)>0;
  $purpose=$party->type==='customer'?($positive?'receivable':'advance'):($positive?'payable':'advance');
  $ledgerId=DB::table('entity_ledger_accounts')->where(['entity_type'=>$party->type,'entity_id'=>$party->id,'currency_id'=>$currencyId,'purpose'=>$purpose])->value('ledger_account_id');
  if(!$ledgerId)throw ValidationException::withMessages(['opening_balances'=>'Missing party ledger mapping.']);
  $parent=LedgerAccount::where('code','3500')->firstOrFail();
  $offset=LedgerAccount::firstOrCreate(['code'=>'3500.OPEN'],['name'=>'Opening Balance Clearing','type'=>$parent->type,'parent_id'=>$parent->id,'is_group'=>false,'is_active'=>true,'description'=>'Opening balances; reconcile with migration trial balance']);
  $type='trade_opening';DocumentSequence::firstOrCreate(['document_type'=>$type],['prefix'=>'OB-','next_number'=>1,'padding'=>6,'is_active'=>true]);$number=DocumentSequence::issue($type);
  $journal=JournalEntry::create(['number'=>$number,'entry_date'=>$date,'description'=>'Opening balance: '.$party->name,'currency_id'=>$currencyId,'created_by'=>$userId,'status'=>'draft']);
  $debitToParty=$party->type==='customer'?$positive:!$positive;$value=ltrim($amount,'-');
  $journal->lines()->create(['ledger_account_id'=>$ledgerId,'debit'=>$debitToParty?$value:0,'credit'=>$debitToParty?0:$value]);
  $journal->lines()->create(['ledger_account_id'=>$offset->id,'debit'=>$debitToParty?0:$value,'credit'=>$debitToParty?$value:0]);
  $posting->post($journal,$userId);
  DB::table('trade_opening_balances')->insert(['trade_party_id'=>$party->id,'currency_id'=>$currencyId,'amount'=>$amount,'balance_date'=>$date,'journal_entry_id'=>$journal->id,'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
 }
}
