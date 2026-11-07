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
