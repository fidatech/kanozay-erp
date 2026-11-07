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
  $required=DB::table('quality_specifications')->where('subject_type','material')
    ->where('subject_id',$layer->inventory_material_id)->where('is_active',true)
    ->pluck('id')->map(fn($id)=>(int)$id)->all();
  $sample=DB::table('quality_samples')->where('goods_receipt_line_id',$layer->source_id)
    ->orderByDesc('id')->first();
  if(!$sample)return count($required)>0;
  if($sample->status!=='approved')return true;
  if(!$required)return false;
  $passed=DB::table('quality_sample_results')->where('quality_sample_id',$sample->id)
    ->where('outcome','pass')->pluck('quality_specification_id')->map(fn($id)=>(int)$id)->all();
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
