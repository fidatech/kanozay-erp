<?php
namespace App\Services;
use App\Models\{ActivityLog,FinishedGood,ProductionBatch,SalesReturnQuarantine};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnQuarantineService {
 public function decide(int $quarantineId,array $data,int $actorId):SalesReturnQuarantine
 {
  return DB::transaction(function()use($quarantineId,$data,$actorId){
   // Lock order: batch before quarantine and finished good, as in stock issue.
   $source=SalesReturnQuarantine::findOrFail($quarantineId);
   $batch=ProductionBatch::whereKey($source->production_batch_id)->lockForUpdate()->firstOrFail();
   $record=SalesReturnQuarantine::whereKey($source->id)->lockForUpdate()->firstOrFail();
   if($record->status!=='quarantined')
    throw ValidationException::withMessages(['action'=>'Return was already released or scrapped.']);
   if($record->registered_by===$actorId)
    throw ValidationException::withMessages(['action'=>'Independent QC authorization is required for returned goods.']);
   if($data['action']==='release'){
    if($batch->status!=='released')
     throw ValidationException::withMessages(['action'=>'Only a released batch can receive a saleable QC return.']);
    app(FinishedGoodLotControlService::class)->assertDispatchAllowed($batch,$data['decided_on']);
    if(DB::table('quality_samples')->where('production_batch_id',$batch->id)
      ->where('status','!=','approved')->exists())
      throw ValidationException::withMessages(['action'=>'An unapproved batch QC sample prevents this return release.']);
    $good=FinishedGood::whereKey($batch->finished_good_id)->lockForUpdate()->firstOrFail();
    if(!$good->is_active)
      throw ValidationException::withMessages(['action'=>'Product is inactive and cannot be restocked.']);
    if($record->quantity_packages>PHP_INT_MAX-(int)$good->on_hand_packages)
      throw ValidationException::withMessages(['action'=>'Product stock exceeds supported quantity.']);
    $warehouseId=$data['company_warehouse_id']??$record->received_warehouse_id;
    // Finance recognizes returned FG asset only when QC physically releases
    // the product to saleable stock. A cash credit alone is insufficient.
    app(FinishedGoodsCostPostingService::class)
        ->postReturnRecovery($record,$data['decided_on'],$actorId);
    app(FinishedGoodWarehouseService::class)->restock(
      $batch,$warehouseId,$record->quantity_packages,$data['decided_on'],
      'Quarantined customer return QC '.$record->id,$actorId
    );
    $good->forceFill(['on_hand_packages'=>(int)$good->on_hand_packages+$record->quantity_packages])->save();
    $status='released';
   }elseif($data['action']==='scrap'){
    $warehouseId=null;
    $status='scrapped';
   }else{
    throw ValidationException::withMessages(['action'=>'Unknown QC disposition.']);
   }
   $record->update([
    'status'=>$status,'released_warehouse_id'=>$warehouseId,
    'decided_by'=>$actorId,'decided_on'=>$data['decided_on'],
    'quality_reference'=>$data['quality_reference'],
    'decision_notes'=>$data['decision_notes'],
   ]);
   ActivityLog::record('sales.return.quarantine.'.$status,'sales_return_quarantine',$record->id,
    'Returned packages QC disposition approved: '.$status,
    ['batch_id'=>$batch->id,'quantity'=>$record->quantity_packages,
     'quality_reference'=>$data['quality_reference']]);
   return $record->fresh();
  });
 }
}