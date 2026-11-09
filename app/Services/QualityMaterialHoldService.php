<?php
namespace App\Services;

use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material release gate, driven by the actual GRN line and approved QC results.
 * No approved QC specification is invented; if specifications exist for a
 * material, newly received lots require a passing sample before production use.
 */
class QualityMaterialHoldService {
 public function isLayerHeld(InventoryCostLayer $layer):bool {
  if($layer->source_type!=='goods_receipt_line')return false;
  // A documented unresolved MAJOR/CRITICAL nonconformance is an
  // immediate issue/dispatch block even if the latest sample had passed.
  // This avoids depending on staff to remember a second manual hold.
  if(DB::table('quality_nonconformances as ncr')
    ->leftJoin('quality_samples as sample',
      'sample.id','=','ncr.quality_sample_id')
    ->whereIn('ncr.severity',['major','critical'])
    ->whereIn('ncr.status',['open','investigating'])
    ->where(function($q)use($layer){
      $q->where('ncr.inventory_cost_layer_id',$layer->id)
        ->orWhere('sample.goods_receipt_line_id',$layer->source_id);
    })->exists())return true;
  // An explicit safety hold has priority over prior passing samples.
  if(DB::table('quality_material_lot_holds')
    ->where('inventory_cost_layer_id',$layer->id)->where('status','held')->exists())return true;

  $active=DB::table('quality_specifications')
    ->where('subject_type','material')
    ->where('subject_id',$layer->inventory_material_id)
    ->where('is_active',true)->pluck('id')->map(fn($id)=>(int)$id)->all();
  $sample=DB::table('quality_samples')
    ->where('goods_receipt_line_id',$layer->source_id)->orderByDesc('id')->first();
  if(!$sample)return count($active)>0;
  if($sample->status!=='approved')return true;

  // Newly collected samples retain the specification versions assigned
  // when collected. Later specification edits cannot rewrite historic
  // approved test evidence. Legacy records without a frozen set still
  // require today's active specification versions as a conservative gate.
  $frozen=DB::table('quality_sample_required_specifications')
    ->where('quality_sample_id',$sample->id)->pluck('quality_specification_id')
    ->map(fn($id)=>(int)$id)->all();
  $required=$frozen?:$active;
  if(!$required)return false;
  $passed=DB::table('quality_sample_results')
    ->where('quality_sample_id',$sample->id)->where('outcome','pass')
    ->whereIn('quality_specification_id',$required)
    ->pluck('quality_specification_id')->map(fn($id)=>(int)$id)->all();
  return count(array_diff($required,$passed))>0;
 }
 public function heldQuantity(InventoryMaterial $material):BigDecimal {
  $held=BigDecimal::zero();
  foreach(InventoryCostLayer::where('inventory_material_id',$material->id)
     ->where('source_type','goods_receipt_line')->where('remaining_quantity','>',0)->get() as $layer){
   if($this->isLayerHeld($layer))$held=$held->plus((string)$layer->remaining_quantity);
  }
  return $held;
 }
 public function safeAvailable(InventoryMaterial $material):BigDecimal {
  return BigDecimal::of((string)$material->on_hand_quantity)
   ->minus($this->heldQuantity($material))
   ->minus((string)$material->reserved_quantity);
 }
 public function assertAvailable(InventoryMaterial $material,BigDecimal $quantity):void {
  if($this->safeAvailable($material)->isLessThan($quantity))
   throw ValidationException::withMessages(['quality_hold'=>
     'Material is under incoming-lot QC hold, or the independently released quantity is insufficient.']);
 }
}
