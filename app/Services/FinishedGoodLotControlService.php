<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinishedGoodLotControlService
{
 public function assertDispatchAllowed(ProductionBatch $batch,string $date):void
 {
  $control=DB::table('finished_good_lot_controls')->where('production_batch_id',$batch->id)->first();
  if(!$control)return; // Legacy batches remain subject to existing release/QC safeguards.
  if($control->status!=='active')
   throw ValidationException::withMessages(['dispatch'=>'Finished-good batch is under a quality hold or recall. Dispatch is blocked.']);
  if($control->expires_on!==null && $date>=$control->expires_on)
   throw ValidationException::withMessages(['dispatch'=>'Finished-good batch has reached its recorded expiration date.']);
 }

 public function register(ProductionBatch $batch,array $data,int $actorId):object
 {
  return DB::transaction(function()use($batch,$data,$actorId){
   $batch=ProductionBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
   if($batch->status!=='released')
    throw ValidationException::withMessages(['production_batch_id'=>'Only previously released finished-good batches can have a warehouse shelf-life record.']);
   $existing=DB::table('finished_good_lot_controls')->where('production_batch_id',$batch->id)
    ->lockForUpdate()->first();
   if($existing)throw ValidationException::withMessages(['production_batch_id'=>'Batch shelf-life control already exists. Use a recorded QC decision to update it.']);
   if(!empty($data['manufactured_on'])&& !empty($data['expires_on']) &&
      $data['expires_on']<=$data['manufactured_on'])
    throw ValidationException::withMessages(['expires_on'=>'Expiry must follow manufacturing date.']);
   if(empty($data['expires_on'])&&empty($data['traceability_reference']))
    throw ValidationException::withMessages(['expires_on'=>'Provide a documented expiry date or quality traceability reference.']);
   $status=(!empty($data['expires_on'])&&$data['expires_on']<=today()->toDateString())?'held':'active';
   $id=DB::table('finished_good_lot_controls')->insertGetId([
    'production_batch_id'=>$batch->id,
    'manufactured_on'=>$data['manufactured_on']??null,'expires_on'=>$data['expires_on']??null,
    'status'=>$status,'traceability_reference'=>$data['traceability_reference']??null,
    'risk_notes'=>$data['risk_notes']??null,
    'registered_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('finished_good_lot_control_events')->insert([
    'finished_good_lot_control_id'=>$id,'action'=>'register','previous_status'=>null,
    'new_status'=>$status,'reason'=>'Initial approved batch shelf-life registration',
    'authorization_ref'=>$data['authorization_ref'],'actor_id'=>$actorId,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finished_good.lot.registered','production_batch',$batch->id,
    'Finished-good lot expiry/traceability record registered.', ['lot_control_id'=>$id]);
   return DB::table('finished_good_lot_controls')->where('id',$id)->first();
  });
 }

 public function decide(ProductionBatch $batch,array $data,int $actorId):object
 {
  return DB::transaction(function()use($batch,$data,$actorId){
   $batch=ProductionBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
   $record=DB::table('finished_good_lot_controls')->where('production_batch_id',$batch->id)
      ->lockForUpdate()->first();
   if(!$record)throw ValidationException::withMessages(['production_batch_id'=>'Register a documented lot first.']);
   if($batch->status!=='released')
    throw ValidationException::withMessages(['production_batch_id'=>'Only released batches can be controlled.']);
   $next=$data['action'];
   $allowed=['active'=>['hold','recall'],'held'=>['release','recall'],'recalled'=>[]];
   if(!in_array($next,$allowed[$record->status]??[],true))
    throw ValidationException::withMessages(['action'=>'Invalid lot release or recall transition. Recalled lots cannot be reopened.']);
   if($next==='release'){
    if($record->registered_by===$actorId || $record->last_decided_by===$actorId)
     throw ValidationException::withMessages(['action'=>'A different QC reviewer must authorize releasing this held batch.']);
    if($record->expires_on!==null && $record->expires_on<=today()->toDateString())
     throw ValidationException::withMessages(['action'=>'Expired goods cannot be released for sale.']);
    if(DB::table('quality_samples')->where('production_batch_id',$batch->id)->where('status','!=','approved')->exists())
     throw ValidationException::withMessages(['action'=>'Active or rejected QC samples block lot release.']);
   }
   $status=['hold'=>'held','release'=>'active','recall'=>'recalled'][$next];
   DB::table('finished_good_lot_controls')->where('id',$record->id)->update([
    'status'=>$status,'risk_notes'=>$data['reason'],'last_decided_by'=>$actorId,'updated_at'=>now(),
   ]);
   DB::table('finished_good_lot_control_events')->insert([
    'finished_good_lot_control_id'=>$record->id,'action'=>$next,'previous_status'=>$record->status,
    'new_status'=>$status,'reason'=>$data['reason'],
    'authorization_ref'=>$data['authorization_ref'],
    'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finished_good.lot.'.$next,'production_batch',$batch->id,
    'Batch lot changed from '.$record->status.' to '.$status);
   return DB::table('finished_good_lot_controls')->where('id',$record->id)->first();
  });
 }
}
