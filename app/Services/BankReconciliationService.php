<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FinancialAccount;
use App\Models\MoneyTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
 /**
  * Exactly match a bank statement line to the actual original-currency
  * bank GL posting. The money-transaction existence alone is insufficient.
  */
 private function assertBankJournal(MoneyTransaction $money,object $period,
  string $direction):void
 {
  $journal=DB::table('journal_entries')
   ->where('id',$money->journal_entry_id)->first();
  if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
   DB::table('journal_entries')->where('reversal_of_id',$journal->id)->exists() ||
   (int)$journal->currency_id!==(int)$period->currency_id ||
   (string)$journal->entry_date!==(string)$money->transaction_date)
   throw ValidationException::withMessages(['money_transaction_id'=>
    'Matched bank transaction must have a genuine posted, unreversed, exact-date General Ledger entry in its original bank currency.']);

  $bankLedger=DB::table('entity_ledger_accounts')->where([
   'entity_type'=>'bank','entity_id'=>$period->financial_account_id,
   'currency_id'=>$period->currency_id,'purpose'=>'cash',
  ])->value('ledger_account_id');
  if(!$bankLedger)
   throw ValidationException::withMessages(['money_transaction_id'=>
    'This bank account has no authenticated source-currency cash ledger mapping.']);

  $signed=BigDecimal::zero();
  foreach(DB::table('journal_lines')
    ->where('journal_entry_id',$journal->id)
    ->where('ledger_account_id',$bankLedger)->get(['debit','credit']) as $line)
   $signed=$signed->plus((string)$line->debit)->minus((string)$line->credit);
  $expected=BigDecimal::of((string)$money->amount);
  if($direction==='withdrawal')$expected=$expected->negated();
  if(!$signed->isEqualTo($expected))
   throw ValidationException::withMessages(['money_transaction_id'=>
    'The bank account debit/credit in the posted original journal must equal the exact matched statement deposit or withdrawal, including direction.']);
 }

 public function create(array $data,int $actorId):object
 {
  return DB::transaction(function()use($data,$actorId){
   $bank=FinancialAccount::whereKey($data['financial_account_id'])
    ->where('type','bank')->where('is_active',true)->lockForUpdate()->firstOrFail();
   if($data['period_end']<$data['period_start'])
    throw ValidationException::withMessages(['period_end'=>'End date must not precede the period start.']);
   if(DB::table('bank_reconciliation_periods')->where('financial_account_id',$bank->id)
    ->where('period_start','<=',$data['period_end'])
    ->where('period_end','>=',$data['period_start'])->exists())
    throw ValidationException::withMessages(['period_start'=>'This bank account already has an overlapping reconciliation.']);
   $previous=DB::table('bank_reconciliation_periods')
    ->where('financial_account_id',$bank->id)->where('period_end','<',$data['period_start'])
    ->orderByDesc('period_end')->first();
   if($previous && $previous->status==='approved' &&
     !BigDecimal::of((string)$previous->statement_closing)->isEqualTo((string)$data['statement_opening']))
    throw ValidationException::withMessages(['statement_opening'=>'Opening bank balance must match the last approved statement closing.']);
   $id=DB::table('bank_reconciliation_periods')->insertGetId([
    'number'=>'TMP-'.\Illuminate\Support\Str::uuid(),
    'financial_account_id'=>$bank->id,'currency_id'=>$bank->currency_id,
    'period_start'=>$data['period_start'],'period_end'=>$data['period_end'],
    'statement_opening'=>(string)BigDecimal::of((string)$data['statement_opening'])->toScale(4),
    'statement_closing'=>(string)BigDecimal::of((string)$data['statement_closing'])->toScale(4),
    'status'=>'draft','statement_reference'=>$data['statement_reference'],
    'created_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('bank_reconciliation_periods')->where('id',$id)
    ->update(['number'=>'BREC-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
   ActivityLog::record('finance.bank_reconciliation.started','bank_reconciliation_period',$id,'Bank statement reconciliation created.');
   return DB::table('bank_reconciliation_periods')->where('id',$id)->first();
  });
 }

 public function addLine(int $periodId,array $data,int $actorId):object
 {
  return DB::transaction(function()use($periodId,$data,$actorId){
   $period=DB::table('bank_reconciliation_periods')->where('id',$periodId)->lockForUpdate()->first();
   abort_unless($period,404);
   if($period->status!=='draft')throw ValidationException::withMessages(['statement'=>'Approved statements are immutable.']);
   if(!in_array($data['direction'],['deposit','withdrawal'],true) ||
      !BigDecimal::of((string)$data['amount'])->isPositive() ||
      BigDecimal::of((string)$data['amount'])->isGreaterThan('9999999999999999.9999') ||
      BigDecimal::of((string)$data['amount'])->getScale()>4)
    throw ValidationException::withMessages(['amount'=>
      'Real bank statement lines require a positive original-currency amount, four-decimal precision and a genuine deposit/withdrawal direction.']);
   if($data['posted_on']<$period->period_start || $data['posted_on']>$period->period_end)
    throw ValidationException::withMessages(['posted_on'=>'Bank statement line must fall within the reconciliation period.']);
   $previous=DB::table('bank_statement_entries')->where('submission_token',$data['submission_token'])->first();
   if($previous){
    if((int)$previous->bank_reconciliation_period_id===$periodId &&
      $previous->bank_reference===$data['bank_reference'] &&
      BigDecimal::of((string)$previous->amount)->isEqualTo((string)$data['amount']))return $previous;
    throw ValidationException::withMessages(['submission_token'=>'This bank statement token already belongs to another entry.']);
   }
   $id=DB::table('bank_statement_entries')->insertGetId([
    'bank_reconciliation_period_id'=>$periodId,'submission_token'=>$data['submission_token'],
    'posted_on'=>$data['posted_on'],'direction'=>$data['direction'],
    'amount'=>(string)BigDecimal::of((string)$data['amount'])->toScale(4),
    'bank_reference'=>$data['bank_reference'],'description'=>$data['description']??null,
    'created_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finance.bank_statement.line','bank_reconciliation_period',$periodId,
    'Bank statement line '.$data['bank_reference'].' entered.');
   return DB::table('bank_statement_entries')->where('id',$id)->first();
  });
 }

 private function direction(MoneyTransaction $money,int $bankAccountId):?string
 {
  if((int)($money->source_account_id??0)===$bankAccountId)return 'withdrawal';
  if((int)($money->destination_account_id??0)===$bankAccountId)return 'deposit';
  return null;
 }

 public function match(int $statementId,int $moneyId,int $actorId):object
 {
  return DB::transaction(function()use($statementId,$moneyId,$actorId){
   $initial=DB::table('bank_statement_entries')->where('id',$statementId)->first();
   abort_unless($initial,404);
   $period=DB::table('bank_reconciliation_periods')
    ->where('id',$initial->bank_reconciliation_period_id)->lockForUpdate()->first();
   abort_unless($period,404);
   if($period->status!=='draft')
    throw ValidationException::withMessages(['match'=>'Approved reconciliation matches cannot change.']);
   $entry=DB::table('bank_statement_entries')->where('id',$statementId)->lockForUpdate()->first();
   $transaction=MoneyTransaction::whereKey($moneyId)->lockForUpdate()->firstOrFail();
   if($entry->posted_on!==$transaction->transaction_date)
    throw ValidationException::withMessages(['money_transaction_id'=>'Bank date and recorded transaction date must match. Reconcile timing differences separately.']);
   $journal=DB::table('journal_entries')->where('id',$transaction->journal_entry_id)->first();
   if(!$journal || $journal->status!=='posted')
    throw ValidationException::withMessages(['money_transaction_id'=>'Bank movement must link to a posted General Ledger entry.']);
   if((int)$transaction->currency_id!==(int)$period->currency_id)
    throw ValidationException::withMessages(['money_transaction_id'=>'Bank transaction currency differs from the statement account.']);
   if($this->direction($transaction,(int)$period->financial_account_id)!==$entry->direction)
    throw ValidationException::withMessages(['money_transaction_id'=>'Deposit/withdrawal direction does not match this financial account.']);
   $this->assertBankJournal($transaction,$period,$entry->direction);
   if(!BigDecimal::of((string)$entry->amount)->isEqualTo((string)$transaction->amount))
    throw ValidationException::withMessages(['money_transaction_id'=>'Statement amount and posted transaction amount differ.']);
   if(DB::table('bank_statement_matches')->where('bank_statement_entry_id',$statementId)->exists() ||
      DB::table('bank_statement_matches')->where('financial_account_id',$period->financial_account_id)
        ->where('money_transaction_id',$moneyId)->exists())
    throw ValidationException::withMessages(['money_transaction_id'=>'This statement line or bank transaction is already matched.']);
   $id=DB::table('bank_statement_matches')->insertGetId([
    'bank_statement_entry_id'=>$statementId,'money_transaction_id'=>$moneyId,
    'financial_account_id'=>$period->financial_account_id,'matched_by'=>$actorId,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finance.bank_statement.matched','bank_reconciliation_period',$period->id,
    'Bank movement matched to posted ledger record '.$transaction->id);
   return DB::table('bank_statement_matches')->where('id',$id)->first();
  });
 }

 /**
  * Re-audit a previously approved original-currency bank statement without
  * creating or modifying any journal, bank source row or transaction.
  * Used by financial period close to catch later source changes.
  */
 public function auditApproved(int $periodId):bool
 {
  $period=DB::table('bank_reconciliation_periods')->where('id',$periodId)->first();
  if(!$period || $period->status!=='approved' ||
    !$period->approved_at || !$period->approved_by ||
    (int)$period->approved_by===(int)$period->created_by ||
    (string)$period->period_start>(string)$period->period_end)
   return false;

  $bank=FinancialAccount::whereKey($period->financial_account_id)->first();
  if(!$bank || $bank->type!=='bank' ||
    (int)$bank->currency_id!==(int)$period->currency_id)return false;

  $mapping=DB::table('entity_ledger_accounts')
   ->where('entity_type','bank')
   ->where('entity_id',$period->financial_account_id)
   ->where('currency_id',$period->currency_id)
   ->where('purpose','cash')->first();
  if(!$mapping)return false;

  $in=BigDecimal::zero();$out=BigDecimal::zero();
  $entries=DB::table('bank_statement_entries')
   ->where('bank_reconciliation_period_id',$period->id)->get();
  foreach($entries as $entry){
   if(!in_array($entry->direction,['deposit','withdrawal'],true) ||
     $entry->posted_on<$period->period_start ||
     $entry->posted_on>$period->period_end ||
     !BigDecimal::of((string)$entry->amount)->isPositive())
    return false;
   $match=DB::table('bank_statement_matches')
     ->where('bank_statement_entry_id',$entry->id)->first();
   $money=$match?MoneyTransaction::whereKey($match->money_transaction_id)->first():null;
   if(!$match || !$money ||
     (int)$match->financial_account_id!==(int)$period->financial_account_id ||
     (int)$money->currency_id!==(int)$period->currency_id ||
     (string)$money->transaction_date!==(string)$entry->posted_on ||
     $this->direction($money,(int)$period->financial_account_id)!==$entry->direction ||
     !BigDecimal::of((string)$money->amount)->isEqualTo((string)$entry->amount))
    return false;
   try{
    $this->assertBankJournal($money,$period,$entry->direction);
   }catch(ValidationException $e){return false;}
   if($entry->direction==='deposit')$in=$in->plus((string)$entry->amount);
   else $out=$out->plus((string)$entry->amount);
  }
  if(!BigDecimal::of((string)$period->statement_opening)
      ->plus($in)->minus($out)
      ->isEqualTo((string)$period->statement_closing))
   return false;

  $money=MoneyTransaction::where(function($q)use($period){
      $q->where('source_account_id',$period->financial_account_id)
        ->orWhere('destination_account_id',$period->financial_account_id);
    })
    ->whereDate('transaction_date','>=',$period->period_start)
    ->whereDate('transaction_date','<=',$period->period_end)->get();
  foreach($money as $tx){
   $match=DB::table('bank_statement_matches')
    ->where('financial_account_id',$period->financial_account_id)
    ->where('money_transaction_id',$tx->id)->first();
   if(!$match ||
     !DB::table('bank_statement_entries')->where('id',$match->bank_statement_entry_id)
      ->where('bank_reconciliation_period_id',$period->id)->exists())
    return false;
  }
  $amount=BigDecimal::zero();
  foreach(DB::table('journal_lines as line')
    ->join('journal_entries as entry',
      'entry.id','=','line.journal_entry_id')
    ->where('line.ledger_account_id',$mapping->ledger_account_id)
    ->where('entry.status','posted')
    ->where('entry.currency_id',$period->currency_id)
    ->whereDate('entry.entry_date','<=',$period->period_end)
    ->get(['line.debit','line.credit']) as $line)
    $amount=$amount->plus((string)$line->debit)
      ->minus((string)$line->credit);
  return $amount->isEqualTo((string)$period->statement_closing);
 }

 public function approve(int $periodId,string $notes,int $actorId):object
 {
  return DB::transaction(function()use($periodId,$notes,$actorId){
   $period=DB::table('bank_reconciliation_periods')->where('id',$periodId)->lockForUpdate()->first();
   abort_unless($period,404);
   if($period->status!=='draft')
    throw ValidationException::withMessages(['status'=>'Only draft bank reconciliations may be approved.']);
   if((int)$period->created_by===$actorId)
    throw ValidationException::withMessages(['status'=>'A different authorized user must independently approve the reconciliation.']);
   $entries=DB::table('bank_statement_entries')->where('bank_reconciliation_period_id',$periodId)
     ->lockForUpdate()->get();
   $incoming=BigDecimal::zero();$outgoing=BigDecimal::zero();
   foreach($entries as $row){
    if(!DB::table('bank_statement_matches')->where('bank_statement_entry_id',$row->id)->exists())
     throw ValidationException::withMessages(['status'=>'Every bank statement line must be matched to a posted finance transaction.']);
    if($row->direction==='deposit')$incoming=$incoming->plus((string)$row->amount);
    else $outgoing=$outgoing->plus((string)$row->amount);
   }
   // Reverify each previously matched GL source immediately before signing
   // the statement. A valid journal at matching time may have been reversed.
   foreach($entries as $entry){
    $matched=DB::table('bank_statement_matches')
      ->where('bank_statement_entry_id',$entry->id)->first();
    $money=$matched?MoneyTransaction::whereKey($matched->money_transaction_id)->first():null;
    if(!$matched || !$money ||
      (int)$matched->financial_account_id!==(int)$period->financial_account_id ||
      (int)$money->currency_id!==(int)$period->currency_id ||
      (string)$entry->posted_on!==(string)$money->transaction_date ||
      $this->direction($money,(int)$period->financial_account_id)!==$entry->direction ||
      !BigDecimal::of((string)$entry->amount)->isEqualTo((string)$money->amount))
     throw ValidationException::withMessages(['status'=>
      'An original bank statement match is missing, reversed or no longer agrees with its source money transaction. Reconcile before approval.']);
    $this->assertBankJournal($money,$period,$entry->direction);
   }
   $closing=BigDecimal::of((string)$period->statement_opening)->plus($incoming)->minus($outgoing);
   if(!$closing->isEqualTo((string)$period->statement_closing))
    throw ValidationException::withMessages(['status'=>'The statement opening plus deposits less withdrawals does not equal its closing balance.']);
   $bankJournal=DB::table('entity_ledger_accounts')->where([
    'entity_type'=>'bank','entity_id'=>$period->financial_account_id,
    'currency_id'=>$period->currency_id,'purpose'=>'cash',
   ])->value('ledger_account_id');
   if(!$bankJournal)throw ValidationException::withMessages(['status'=>'Bank GL mapping is missing.']);
   $ledgers=DB::table('journal_lines as l')
     ->join('journal_entries as j','j.id','=','l.journal_entry_id')
     ->where('l.ledger_account_id',$bankJournal)->where('j.status','posted')
     ->where('j.currency_id',$period->currency_id)->whereDate('j.entry_date','<=',$period->period_end)
     ->select('l.debit','l.credit')->get();
   $balance=BigDecimal::zero();
   foreach($ledgers as $line)$balance=$balance->plus((string)$line->debit)->minus((string)$line->credit);
   if(!$balance->isEqualTo((string)$period->statement_closing))
    throw ValidationException::withMessages(['status'=>'The source-currency bank ledger balance differs from the statement closing. Investigate opening entries, fees and uncleared items before approval.']);
   $money=MoneyTransaction::where(function($q)use($period){
      $q->where('source_account_id',$period->financial_account_id)->orWhere('destination_account_id',$period->financial_account_id);
    })->whereDate('transaction_date','>=',$period->period_start)
     ->whereDate('transaction_date','<=',$period->period_end)->get();
   foreach($money as $m){
    if(!DB::table('bank_statement_matches')->where('financial_account_id',$period->financial_account_id)
      ->where('money_transaction_id',$m->id)->exists())
     throw ValidationException::withMessages(['status'=>'One or more posted bank movements are unmatched. Complete matching before approval.']);
   }
   DB::table('bank_reconciliation_periods')->where('id',$periodId)->update([
    'status'=>'approved','review_notes'=>$notes,'approved_by'=>$actorId,
    'approved_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finance.bank_reconciliation.approved','bank_reconciliation_period',$periodId,
     'Approved source-backed statement and General Ledger bank reconciliation.');
   return DB::table('bank_reconciliation_periods')->where('id',$periodId)->first();
  });
 }
}
