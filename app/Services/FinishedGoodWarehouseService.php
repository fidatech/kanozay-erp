<?php

namespace App\Services;

use App\Models\CompanyWarehouse;
use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinishedGoodWarehouseService
{
 public function __construct(private readonly FinishedGoodDispatchService $dispatches){}

 private function warehouse(int $id):CompanyWarehouse
 {
  $warehouse=CompanyWarehouse::whereKey($id)->where('is_active',true)->first();
  if(!$warehouse)throw ValidationException::withMessages(['warehouse'=>'Choose an active company warehouse.']);
  return $warehouse;
 }

 private function rows(int $batchId):\Illuminate\Support\Collection
 {
  return DB::table('finished_good_warehouse_stocks')->where('production_batch_id',$batchId)
   ->lockForUpdate()->get()->keyBy('company_warehouse_id');
 }

 private function balance(\Illuminate\Support\Collection $rows,?int $warehouseId):int
 {
  if($warehouseId===null)return 0;
  return (int)($rows[$warehouseId]->quantity_packages??0);
 }

 private function unassigned(ProductionBatch $batch,\Illuminate\Support\Collection $rows):int
 {
  $assigned=0;
  foreach($rows as $row)$assigned+=(int)$row->quantity_packages;
  $global=$this->dispatches->availablePackages($batch);
  if($assigned>$global)
   throw ValidationException::withMessages(['warehouse'=>'Finished goods warehouse allocation exceeds remaining batch stock. Reconcile the ledger.']);
  return $global-$assigned;
 }

 private function saveStock(ProductionBatch $batch,int $warehouseId,int $quantity):void
 {
  $existing=DB::table('finished_good_warehouse_stocks')->where([
   'production_batch_id'=>$batch->id,'company_warehouse_id'=>$warehouseId,
  ])->first();
  if($existing){
   DB::table('finished_good_warehouse_stocks')->where('id',$existing->id)
     ->update(['quantity_packages'=>$quantity,'updated_at'=>now()]);
  }else{
   DB::table('finished_good_warehouse_stocks')->insert([
    'production_batch_id'=>$batch->id,'company_warehouse_id'=>$warehouseId,
    'quantity_packages'=>$quantity,'created_at'=>now(),'updated_at'=>now(),
   ]);
  }
 }

 private function history(ProductionBatch $batch,?int $source,?int $dest,string $type,
   int $qty,string $date,string $ref,int $actor,string $token):void
 {
  DB::table('finished_good_warehouse_movements')->insert([
   'production_batch_id'=>$batch->id,'source_warehouse_id'=>$source,'destination_warehouse_id'=>$dest,
   'type'=>$type,'quantity_packages'=>$qty,'occurred_on'=>$date,
   'reference'=>$ref,'submission_token'=>$token,'created_by'=>$actor,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
 }

 /**
  * These methods must be called inside the corresponding batch-row lock and
  * transaction of production release / dispatch / customer credit.
  */
 public function receipt(ProductionBatch $batch,?int $warehouseId,int $quantity,string $date,int $actorId):void
 {
  if($warehouseId===null)return; // Existing legacy pool.
  $this->warehouse($warehouseId);
  $rows=$this->rows($batch->id);
  $now=$this->balance($rows,$warehouseId);
  $this->saveStock($batch,$warehouseId,$now+$quantity);
  $this->history($batch,null,$warehouseId,'production_receipt',$quantity,$date,
   'QC production release '.$batch->batch_number,$actorId,(string)Str::uuid());
 }

 public function dispatch(ProductionBatch $batch,?int $warehouseId,int $quantity,string $date,string $reference,int $actorId,string $token):void
 {
  if($quantity<1)throw ValidationException::withMessages(['quantity'=>'Dispatch quantity must be positive.']);
  $rows=$this->rows($batch->id);
  if($warehouseId===null){
   if($quantity>$this->unassigned($batch,$rows))
    throw ValidationException::withMessages(['warehouse'=>'Unallocated finished goods batch stock is insufficient; select the physical source warehouse.']);
  }else{
   $this->warehouse($warehouseId);
   $balance=$this->balance($rows,$warehouseId);
   if($quantity>$balance)throw ValidationException::withMessages(['warehouse'=>'Insufficient batch packages in the selected warehouse.']);
   $this->saveStock($batch,$warehouseId,$balance-$quantity);
  }
  $this->history($batch,$warehouseId,null,'dispatch',$quantity,$date,$reference,$actorId,$token);
 }

 public function restock(ProductionBatch $batch,?int $warehouseId,int $quantity,string $date,string $reference,int $actorId):void
 {
  if($warehouseId===null)return; // Returned to the unassigned legacy pool.
  $this->warehouse($warehouseId);
  $rows=$this->rows($batch->id);
  $this->saveStock($batch,$warehouseId,$this->balance($rows,$warehouseId)+$quantity);
  $this->history($batch,null,$warehouseId,'customer_return',$quantity,$date,$reference,$actorId,(string)Str::uuid());
 }

 public function transfer(ProductionBatch $batch,?int $sourceId,int $destId,int $quantity,
   string $date,string $reason,int $actorId,string $token):object
 {
  return DB::transaction(function()use($batch,$sourceId,$destId,$quantity,$date,$reason,$actorId,$token){
   $locked=ProductionBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
   $existing=DB::table('finished_good_warehouse_movements')->where('submission_token',$token)->first();
   if($existing){
    if($existing->production_batch_id===$locked->id &&
      (int)($existing->source_warehouse_id??0)===(int)($sourceId??0) &&
      (int)$existing->destination_warehouse_id===$destId &&
      (int)$existing->quantity_packages===$quantity &&
      $existing->reference===$reason)return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Token already belongs to another movement.']);
   }
   if($locked->status!=='released')throw ValidationException::withMessages(['production_batch_id'=>'Only released finished goods can be physically transferred.']);
   if($quantity<1||$quantity>2147483647)throw ValidationException::withMessages(['quantity_packages'=>'Quantity is outside supported range.']);
   if($sourceId===$destId)throw ValidationException::withMessages(['destination_warehouse_id'=>'Destination must differ from source.']);
   $this->warehouse($destId);
   if($sourceId!==null)$this->warehouse($sourceId);
   $rows=$this->rows($locked->id);
   $from=$sourceId===null?$this->unassigned($locked,$rows):$this->balance($rows,$sourceId);
   if($from<$quantity)throw ValidationException::withMessages(['quantity_packages'=>'Insufficient packages in the source location.']);
   if($sourceId!==null)$this->saveStock($locked,$sourceId,$from-$quantity);
   $target=$this->balance($rows,$destId);
   $this->saveStock($locked,$destId,$target+$quantity);
   $this->history($locked,$sourceId,$destId,$sourceId===null?'initial_allocation':'transfer',
     $quantity,$date,$reason,$actorId,$token);
   return DB::table('finished_good_warehouse_movements')->where('submission_token',$token)->first();
  });
 }
}
