<?php
namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductionCostReadinessService {
 /**
  * Advisory manufacturing cost completeness, source-currency only.
  * This is NOT a GL capitalization, inventory valuation posting or COGS.
  */
 public function rows(Collection $batches):Collection {
  $ids=$batches->pluck('id')->all();
  if(!$ids)return collect();
  $overhead=DB::table('production_overhead_allocations as o')
   ->join('currencies as c','c.id','=','o.currency_id')
   ->whereIn('o.production_batch_id',$ids)
   ->select('o.production_batch_id','o.status','o.amount','c.code as currency_code')
   ->get()->groupBy('production_batch_id');
  $capitalized=DB::table('production_batch_cost_postings')
   ->whereIn('production_batch_id',$ids)
   ->get()->keyBy('production_batch_id');
  $wastes=DB::table('production_material_wastes as waste')
   ->join('production_order_materials as item',
      'item.id','=','waste.production_order_material_id')
   ->leftJoin('production_waste_financial_reviews as review',
      'review.production_material_waste_id','=','waste.id')
   ->whereIn('item.production_order_id',$batches->pluck('production_order_id'))
   ->select('item.production_order_id','review.treatment')->get()
   ->groupBy('production_order_id');
  $result=collect();
  foreach($batches as $batch){
   $costs=$batch->costs;
   $overheadRows=$overhead->get($batch->id,collect());
   $sourcePosting=$capitalized->get($batch->id);
   $pending=$overheadRows->where('status','submitted')->count();
   $wasteRows=$wastes->get($batch->production_order_id,collect());
   $unreviewedWaste=$wasteRows->filter(fn($item)=>$item->treatment===null)->count();
   $unresolvedWaste=$wasteRows->whereIn('treatment',['abnormal_loss','rework_required'])->count();
   $hasUnvalued=$costs->isEmpty() || $costs->contains(function($c){
     return $c->material_cost===null ||
       BigDecimal::of((string)$c->unvalued_quantity)->isGreaterThan(0);
   });
   $currencyCodes=$costs->pluck('currency_code')->filter()
    ->merge($overheadRows->where('status','approved')->pluck('currency_code'))
    ->unique()->values();
   if($currencyCodes->isEmpty())$currencyCodes=collect([null]);
   $mixedCurrency=$currencyCodes->count()>1;
   foreach($currencyCodes as $code){
    $material=BigDecimal::zero();$overheadTotal=BigDecimal::zero();
    $hasMaterialCurrency=false;
    foreach($costs->where('currency_code',$code) as $cost){
     if($cost->material_cost!==null){
       $material=$material->plus((string)$cost->material_cost);
       $hasMaterialCurrency=true;
     }
    }
    foreach($overheadRows as $allocation){
     if($allocation->status==='approved'&&$allocation->currency_code===$code)
      $overheadTotal=$overheadTotal->plus((string)$allocation->amount);
    }
    $complete=$batch->status==='released' &&
      $batch->accepted_packages>0 && $hasMaterialCurrency &&
      !$hasUnvalued && !$mixedCurrency && $pending===0 &&
      $unreviewedWaste===0 && $unresolvedWaste===0;
    $costPerPackage=$complete?
      (string)$material->plus($overheadTotal)
       ->dividedBy((string)$batch->accepted_packages,8,RoundingMode::HalfUp):null;
    $warning=match(true){
      $unreviewedWaste>0=>'Recorded material waste requires independent finance classification',
      $unresolvedWaste>0=>'Abnormal production waste or unresolved rework requires a separate approved cost disposition',
      $sourcePosting!==null=>'Posted to original-currency WIP and finished goods GL; review source evidence',
      $hasUnvalued=>'Missing or unvalued material source costs',
      $mixedCurrency=>'Multiple source currencies; no combined cost',
      $pending>0=>'Unapproved overhead remains',
      $batch->status!=='released'=>'Batch not released',
      $batch->accepted_packages<1=>'No accepted packages',
      $complete=>'Source cost snapshot ready; finance must verify source journals and approve capitalization',
      default=>'Material source cost unavailable',
    };
    $result->push([
     'batch_id'=>$batch->id,'batch'=>$batch->batch_number,
     'product'=>$batch->finishedGood?->name??'Unknown',
     'status'=>$batch->status,'packages'=>$batch->accepted_packages??0,
     'currency'=>$code??'Unvalued',
     'material_cost'=>$hasMaterialCurrency?(string)$material->toScale(4,RoundingMode::HalfUp):null,
     'approved_overhead'=>(string)$overheadTotal->toScale(4,RoundingMode::HalfUp),
     'pending_overheads'=>$pending,
     'unreviewed_waste'=>$unreviewedWaste,
     'unresolved_waste'=>$unresolvedWaste,
     'has_unvalued'=>$hasUnvalued,
     'mixed_currency'=>$mixedCurrency,'ready'=>$complete,
     'cost_per_package'=>$costPerPackage,
     'gl_posted'=>$sourcePosting!==null,
     'gl_currency_id'=>$sourcePosting?->currency_id,
     'gl_accounted_cost'=>$sourcePosting?->total_amount,
     'warning'=>$warning,
    ]);
   }
  }
  return $result;
 }
}