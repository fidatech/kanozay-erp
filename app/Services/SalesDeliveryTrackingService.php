<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SalesDeliveryNote;
use App\Models\SalesDeliveryTrackingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDeliveryTrackingService
{
 private const PROGRESS=[
  'loaded'=>['in_transit','attempted','delivered'],
  'in_transit'=>['in_transit','attempted','delivered'],
  'attempted'=>['in_transit','attempted','delivered','returned'],
  'returned'=>[],
  'delivered'=>[],
 ];
 public function record(SalesDeliveryNote $note,array $data,int $actorId):SalesDeliveryTrackingEvent
 {
  return DB::transaction(function()use($note,$data,$actorId){
   $locked=SalesDeliveryNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
   $token=SalesDeliveryTrackingEvent::where('submission_token',$data['submission_token'])->first();
   if($token){
    if((int)$token->sales_delivery_note_id===(int)$locked->id &&
        $token->status===$data['status'] && $token->location===$data['location'] &&
        $token->occurred_at->format('Y-m-d H:i:s')===
          CarbonImmutable::parse($data['occurred_at'])->format('Y-m-d H:i:s') &&
        (string)($token->proof_reference??'')===(string)($data['proof_reference']??'') &&
        (string)($token->receiver_name??'')===(string)($data['receiver_name']??''))
       return $token;
    throw ValidationException::withMessages(['submission_token'=>'This tracking token belongs to a different event.']);
   }
   if($locked->status!=='issued')
    throw ValidationException::withMessages(['status'=>'Tracking cannot be changed on delivered or cancelled notes.']);
   $eventTime=CarbonImmutable::parse($data['occurred_at']);
   $start=$locked->delivery_date->startOfDay();
   if($eventTime->lessThan($start)||$eventTime->greaterThan(now()))
    throw ValidationException::withMessages(['occurred_at'=>'Tracking cannot precede delivery-note date or occur in the future.']);
   $prior=SalesDeliveryTrackingEvent::where('sales_delivery_note_id',$locked->id)
    ->orderByDesc('id')->first();
   $priorStatus=$prior?->status??'loaded';
   if($prior && $eventTime->lessThan($prior->occurred_at))
    throw ValidationException::withMessages(['occurred_at'=>'Tracking events must follow the previous timestamp.']);
   if($prior && !in_array($data['status'],self::PROGRESS[$priorStatus]??[],true))
    throw ValidationException::withMessages(['status'=>'This delivery tracking transition is not permitted.']);
   if(!$prior && !in_array($data['status'],['loaded','in_transit','attempted','delivered'],true))
    throw ValidationException::withMessages(['status'=>'Initial delivery event cannot be a return.']);
   if($data['status']==='delivered' &&
      (empty($data['proof_reference'])||empty($data['receiver_name'])))
    throw ValidationException::withMessages(['proof_reference'=>'Delivery confirmation requires receiver name and proof reference.']);
   if($data['status']==='returned' && empty($data['proof_reference']))
    throw ValidationException::withMessages(['proof_reference'=>'Returned cargo requires a return document reference.']);
   $record=SalesDeliveryTrackingEvent::create([
    'submission_token'=>$data['submission_token'],'sales_delivery_note_id'=>$locked->id,
    'status'=>$data['status'],'occurred_at'=>$eventTime->format('Y-m-d H:i:s'),
    'location'=>$data['location'],'proof_reference'=>$data['proof_reference']??null,
    'receiver_name'=>$data['receiver_name']??null,'notes'=>$data['notes']??null,
    'created_by'=>$actorId,
   ]);
   if($data['status']==='delivered'){
    $locked->update([
     'status'=>'delivered','received_by'=>$data['receiver_name'],
     'delivered_at'=>$eventTime->format('Y-m-d H:i:s'),'delivered_by'=>$actorId,
    ]);
   }
   ActivityLog::record('sales.delivery.tracking.'.$data['status'],'sales_delivery_note',$locked->id,
    'Recorded delivery tracking milestone for '.$locked->number.'. Inventory and GL unaffected.',
    ['tracking_event_id'=>$record->id,'status'=>$data['status']]);
   return $record;
  });
 }
}