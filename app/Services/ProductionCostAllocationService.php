<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\JournalLine;
use App\Models\ProductionBatch;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionCostAllocationService
{
 private function available(int $journalLineId):BigDecimal
 {
  $line=JournalLine::with(['account'])->whereKey($journalLineId)->firstOrFail();
  $left=BigDecimal::of((string)$line->debit);
  foreach(DB::table('production_overhead_allocations')
     ->where('source_journal_line_id',$journalLineId)
     ->whereIn('status',['submitted','approved'])->get() as $row)
   $left=$left->minus((string)$row->amount);
  return $left;
 }

 public function submit(array $data,int $actorId):object
 {
  return DB::transaction(function()use($data,$actorId){
   $existing=DB::table('production_overhead_allocations')->where('submission_token',$data['submission_token'])->first();
   if($existing){
    if((int)$existing->production_batch_id===(int)$data['production_batch_id'] &&
        (int)$existing->source_journal_line_id===(int)$data['source_journal_line_id'] &&
        BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']))return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Duplicate cost allocation token already exists.']);
   }
   $batch=ProductionBatch::whereKey($data['production_batch_id'])->lockForUpdate()->firstOrFail();
   if(in_array($batch->status,['qc_rejected','rejected'],true)){
    throw ValidationException::withMessages([
      'production_batch_id'=>'Rejected production batches cannot receive new production cost allocations.',
    ]);
   }
   if(DB::table('finished_good_dispatch_lines')->where('production_batch_id',$batch->id)->exists()){
    throw ValidationException::withMessages([
      'production_batch_id'=>'This batch has already been dispatched. Historical production unit-cost inputs cannot be revised after a sale.',
    ]);
   }
   $line=JournalLine::whereKey($data['source_journal_line_id'])->lockForUpdate()->firstOrFail();
   $account=$line->account;
   $journal=DB::table('journal_entries')->where('id',$line->journal_entry_id)->first();
   if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
    DB::table('journal_entries')->where('reversal_of_id',$journal->id)->exists())
    throw ValidationException::withMessages(['source_journal_line_id'=>'Source must be an original, posted expense journal.']);
   if(!$account || !$account->is_active || $account->is_group || $account->type!=='expense' ||
     BigDecimal::of((string)$line->debit)->isLessThanOrEqualTo(0) ||
     BigDecimal::of((string)$line->credit)->isGreaterThan(0))
    throw ValidationException::withMessages(['source_journal_line_id'=>'Select a posted debit expense line.']);
   $amount=BigDecimal::of((string)$data['amount'])->toScale(4);
   if(!$amount->isPositive() || $amount->isGreaterThan($this->available($line->id)))
    throw ValidationException::withMessages(['amount'=>'Allocation exceeds unallocated expense debit of the source journal line.']);
   $id=DB::table('production_overhead_allocations')->insertGetId([
    'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
    'production_batch_id'=>$batch->id,'source_journal_line_id'=>$line->id,
    'currency_id'=>$journal->currency_id,'category'=>$data['category'],
    'amount'=>(string)$amount,'status'=>'submitted',
    'source_reference'=>$data['source_reference'],
    'notes'=>$data['notes']??null,'created_by'=>$actorId,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('production_overhead_allocations')->where('id',$id)
    ->update(['number'=>'POH-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
   ActivityLog::record('production.overhead.submitted','production_overhead_allocation',$id,
    'Source-expense-backed production overhead submitted for independent review.',
    ['batch_id'=>$batch->id,'journal_line_id'=>$line->id,'amount'=>(string)$amount]);
   return DB::table('production_overhead_allocations')->where('id',$id)->first();
  });
 }

 public function decide(int $id,string $decision,string $note,int $actorId):object
 {
  return DB::transaction(function()use($id,$decision,$note,$actorId){
   $row=DB::table('production_overhead_allocations')->where('id',$id)->lockForUpdate()->first();
   abort_unless($row,404);
   if($row->status!=='submitted')
    throw ValidationException::withMessages(['decision'=>'Only submitted overhead allocations can be reviewed.']);
   if($row->created_by===$actorId)
    throw ValidationException::withMessages(['decision'=>'Overhead approvals require an independent finance reviewer.']);
   if($decision==='approved'){
    $batch=ProductionBatch::whereKey($row->production_batch_id)->lockForUpdate()->firstOrFail();
    if(in_array($batch->status,['qc_rejected','rejected'],true) ||
      DB::table('finished_good_dispatch_lines')
        ->where('production_batch_id',$batch->id)->exists()){
      throw ValidationException::withMessages([
        'decision'=>'Do not approve production overhead after a batch was rejected or dispatched. Use a separately reviewed accounting correction if needed.',
      ]);
    }
    $line=JournalLine::whereKey($row->source_journal_line_id)->lockForUpdate()->firstOrFail();
    // Excludes this submitted row, which already reserves its source amount.
    $total=BigDecimal::of((string)$row->amount);
    foreach(DB::table('production_overhead_allocations')
      ->where('source_journal_line_id',$line->id)
      ->where('id','!=',$row->id)->whereIn('status',['submitted','approved'])->get() as $taken)
     $total=$total->plus((string)$taken->amount);
    if($total->isGreaterThan((string)$line->debit))
     throw ValidationException::withMessages(['decision'=>'Journal expense was already fully allocated.']);
   }
   DB::table('production_overhead_allocations')->where('id',$id)->update([
     'status'=>$decision,'review_note'=>$note,
     'reviewed_by'=>$actorId,'reviewed_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('production.overhead.'.$decision,'production_overhead_allocation',$id,
     'Manufacturing overhead '.$decision.' with independent finance review.');
   return DB::table('production_overhead_allocations')->where('id',$id)->first();
  });
 }
}
