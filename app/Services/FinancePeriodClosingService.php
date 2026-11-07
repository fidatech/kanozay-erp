<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePeriodClosingService
{
 public function holdPostingGate():void
 {
  // A shared lock row serializes closed-month changes with concurrent journal posts.
  DB::table('finance_posting_gates')->where('id',1)->lockForUpdate()->firstOrFail();
 }
 public function assertOpenDate(string $date):void
 {
  $start=substr($date,0,7).'-01';
  if(DB::table('finance_period_closures')->where('period_start',$start)->where('status','closed')->exists())
   throw ValidationException::withMessages(['entry_date'=>'The accounting period '.substr($date,0,7).' is closed. Reopening requires independent authorization.']);
 }
 public function close(array $data,int $actorId):void
 {
  DB::transaction(function()use($data,$actorId){
   $this->holdPostingGate();
   $start=\Carbon\CarbonImmutable::parse($data['period_start'])->startOfMonth();
   if($data['period_start']!==$start->format('Y-m-d'))
    throw ValidationException::withMessages(['period_start'=>'Use the first day of a calendar month.']);
   if($start->greaterThan(\Carbon\CarbonImmutable::today()->startOfMonth()))
    throw ValidationException::withMessages(['period_start'=>'Future months cannot be closed.']);
   $end=$start->addMonth();
   $existing=DB::table('finance_period_closures')->where('period_start',$start->format('Y-m-d'))->lockForUpdate()->first();
   if($existing && $existing->status==='closed')
    throw ValidationException::withMessages(['period_start'=>'This month is already closed.']);
   $all=JournalEntry::where('entry_date','>=',$start->toDateString())->where('entry_date','<',$end->toDateString())->get();
   if($all->contains(fn($j)=>$j->status==='draft'))
    throw ValidationException::withMessages(['period_start'=>'Draft journals remain in this month. Post or cancel them before closing.']);
   $posted=$all->where('status','posted');
   $debit=BigDecimal::zero();$credit=BigDecimal::zero();
   $ids=$posted->pluck('id');
   if($ids->isNotEmpty()){
    foreach(DB::table('journal_lines')->whereIn('journal_entry_id',$ids)->get() as $line){
     $debit=$debit->plus((string)$line->debit);
     $credit=$credit->plus((string)$line->credit);
    }
   }
   if(!$debit->isEqualTo($credit))
    throw ValidationException::withMessages(['period_start'=>'Unbalanced journal totals prevent closure.']);
   if($existing){
    // A reopened month can be closed again without losing previous event history.
    DB::table('finance_period_closures')->where('id',$existing->id)->update([
     'status'=>'closed','journal_count'=>$posted->count(),
     'debit_snapshot'=>(string)$debit->toScale(4),'credit_snapshot'=>(string)$credit->toScale(4),
     'authorization_reference'=>$data['authorization_reference'],'closing_note'=>$data['closing_note'],
     'closed_by'=>$actorId,'closed_at'=>now(),'updated_at'=>now(),
    ]);
    $id=$existing->id;
   }else{
    $id=DB::table('finance_period_closures')->insertGetId([
     'period_start'=>$start->toDateString(),'status'=>'closed',
     'journal_count'=>$posted->count(),'debit_snapshot'=>(string)$debit->toScale(4),
     'credit_snapshot'=>(string)$credit->toScale(4),
     'authorization_reference'=>$data['authorization_reference'],'closing_note'=>$data['closing_note'],
     'closed_by'=>$actorId,'closed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
   }
   DB::table('finance_period_closure_events')->insert([
    'finance_period_closure_id'=>$id,'action'=>'closed','reference'=>$data['authorization_reference'],
    'notes'=>$data['closing_note'],'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finance.period.closed','finance_period_closure',$id,
     'Finance ledger period closed after balanced journal verification.',
     ['period_start'=>$start->toDateString(),'posted_journals'=>$posted->count()]);
  });
 }
 public function reopen(int $id,array $data,int $actorId):void
 {
  DB::transaction(function()use($id,$data,$actorId){
   $this->holdPostingGate();
   $closed=DB::table('finance_period_closures')->where('id',$id)->lockForUpdate()->first();
   abort_unless($closed,404);
   if($closed->status!=='closed')
    throw ValidationException::withMessages(['period'=>'Only closed months can be reopened.']);
   if((int)$closed->closed_by===$actorId)
    throw ValidationException::withMessages(['period'=>'Reopening requires an independent finance approver.']);
   DB::table('finance_period_closures')->where('id',$id)->update([
    'status'=>'reopened','reopened_by'=>$actorId,'reopened_at'=>now(),
    'reopening_reference'=>$data['reopening_reference'],
    'reopening_note'=>$data['reopening_note'],'updated_at'=>now(),
   ]);
   DB::table('finance_period_closure_events')->insert([
    'finance_period_closure_id'=>$id,'action'=>'reopened','reference'=>$data['reopening_reference'],
    'notes'=>$data['reopening_note'],'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finance.period.reopened','finance_period_closure',$id,
    'Finance posting period reopened by a different authorized user.',
    ['period_start'=>$closed->period_start]);
  });
 }
}