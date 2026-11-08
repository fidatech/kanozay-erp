<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionWorkStepService
{
 /**
  * Called when an approved-recipe order is first planned; routing is frozen
  * and cannot silently change mid-production.
  */
 public function initializeOrder(ProductionOrder $order):void
 {
  $routes=DB::table('operational_registers')->where('workflow','manufacturing.routing')
    ->where('data->recipe',$order->production_recipe_id)
    ->whereIn('status',['approved','closed'])->orderBy('id')->get();
  $steps=[];
  foreach($routes as $route){
   $d=json_decode($route->data,true);
   if(!is_array($d) || !isset($d['step']) ||
      filter_var($d['step'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]])===false ||
      !is_string($d['operation']??null) ||
      trim($d['operation'])==='' || mb_strlen($d['operation'])>180){
    throw ValidationException::withMessages([
     'production_recipe_id'=>'Approved routing record '.$route->id.' is incomplete. Correct its stage number and operation before creating this production plan.',
    ]);
   }
   $equipmentId=empty($d['equipment'])?null:(int)$d['equipment'];
   if($equipmentId && !DB::table('manufacturing_equipment')
       ->where('id',$equipmentId)->where('is_active',true)->exists()){
    throw ValidationException::withMessages([
     'production_recipe_id'=>'Approved routing stage '.$d['step'].' uses an inactive or missing manufacturing machine.',
    ]);
   }
   $minutes=empty($d['estimated_minutes'])?null:(int)$d['estimated_minutes'];
   if($minutes!==null && ($minutes<1 || $minutes>1000000) ||
      mb_strlen((string)($d['instructions']??''))>5000){
    throw ValidationException::withMessages([
     'production_recipe_id'=>'Approved routing has unsupported time or instructions fields.',
    ]);
   }
   $steps[]=['route_id'=>$route->id,'step'=>(int)$d['step'],
     'operation'=>trim($d['operation']),'equipment'=>$equipmentId,
     'minutes'=>$minutes,'instructions'=>(string)($d['instructions']??'')];
  }
  usort($steps,fn($a,$b)=>$a['step']<=>$b['step']);
  $last=null;
  foreach($steps as $record){
   if($last===$record['step'])
    throw ValidationException::withMessages(['production_recipe_id'=>'Approved routing contains duplicate stage numbers. Reconcile before creating a production order.']);
   $last=$record['step'];
   DB::table('production_work_steps')->insert([
    'production_order_id'=>$order->id,'routing_record_id'=>$record['route_id'],
    'step'=>$record['step'],'operation'=>$record['operation'],
    'manufacturing_equipment_id'=>$record['equipment'],
    'estimated_minutes'=>$record['minutes'],'instructions'=>$record['instructions'],
    'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
   ]);
  }
 }

 public function advance(int $workStepId,string $action,?string $notes,int $actorId):object
 {
  return DB::transaction(function()use($workStepId,$action,$notes,$actorId){
   $row=DB::table('production_work_steps')->where('id',$workStepId)->first();
   abort_unless($row,404);
   $order=ProductionOrder::whereKey($row->production_order_id)->lockForUpdate()->firstOrFail();
   $row=DB::table('production_work_steps')->where('id',$workStepId)->lockForUpdate()->first();
   if($order->status!=='issued')
    throw ValidationException::withMessages(['work_step'=>'Work steps can only be executed after material issue and before batch submission.']);
   if($action==='start'){
    if($row->status!=='pending')
     throw ValidationException::withMessages(['work_step'=>'A work step may be started only once.']);
    if(DB::table('production_work_steps')->where('production_order_id',$order->id)
      ->where('step','<',$row->step)->where('status','!=','completed')->exists())
     throw ValidationException::withMessages(['work_step'=>'Complete earlier process stages before starting this operation.']);
    if($row->manufacturing_equipment_id!==null){
     // Lock the machine itself so two parallel order requests cannot start
     // different work steps on the same equipment concurrently.
     $equipment=DB::table('manufacturing_equipment')
      ->where('id',$row->manufacturing_equipment_id)->lockForUpdate()->first();
     if(!$equipment||!$equipment->is_active)
      throw ValidationException::withMessages(['work_step'=>'Routing equipment is inactive.']);
     if(DB::table('production_work_steps')
       ->where('manufacturing_equipment_id',$equipment->id)
       ->where('id','!=',$row->id)->where('status','in_progress')->exists())
      throw ValidationException::withMessages(['work_step'=>'Equipment is currently in use by another production work step. Complete it before starting this stage.']);
     if(DB::table('manufacturing_downtime_entries')
       ->where('manufacturing_equipment_id',$equipment->id)
       ->where('started_at','<=',now())->where('ended_at','>',now())->exists())
      throw ValidationException::withMessages(['work_step'=>'Equipment has recorded downtime at the requested start time.']);
     $calibration=DB::table('operational_registers')
      ->where('workflow','laboratory.calibration')
      ->where('data->equipment',(int)$equipment->id)
      ->whereIn('status',['approved','closed'])->orderByDesc('id')->first();
     if($calibration){
      $result=json_decode($calibration->data,true);
      if(($result['result']??'')!=='pass' || empty($result['due_on']) || $result['due_on']<today()->toDateString())
       throw ValidationException::withMessages(['work_step'=>'Equipment calibration failed or expired.']);
     }
     // Missing calibration records remain a separate quality-policy decision:
     // do not invent an expiry requirement where none was approved.

    }
    DB::table('production_work_steps')->where('id',$row->id)->update([
      'status'=>'in_progress','started_at'=>now(),'started_by'=>$actorId,'updated_at'=>now(),
    ]);
   }elseif($action==='complete'){
    if($row->status!=='in_progress')
     throw ValidationException::withMessages(['work_step'=>'Start the operation before recording completion.']);
    if(!$notes||mb_strlen(trim($notes))<5)
     throw ValidationException::withMessages(['notes'=>'Enter completion measurements, observations or work instructions reference.']);
    DB::table('production_work_steps')->where('id',$row->id)->update([
      'status'=>'completed','completed_at'=>now(),'completed_by'=>$actorId,
      'completion_notes'=>$notes,'updated_at'=>now(),
    ]);
   }else throw ValidationException::withMessages(['action'=>'Invalid production operation action.']);
   ActivityLog::record('manufacturing.work_step.'.$action,'production_order',$order->id,
    'Production work stage '.$row->step.' '.$action,['work_step_id'=>$row->id]);
   return DB::table('production_work_steps')->where('id',$row->id)->first();
  });
 }
}
