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
  $historicRecoveries=DB::table('finance_historical_fifo_production_recoveries as recovery')
   ->join('currencies as currency','currency.id','=','recovery.currency_id')
   ->whereIn('recovery.production_batch_id',$ids)
   ->where('recovery.status','posted')
   ->select('recovery.*','currency.code as historical_currency_code')
   ->get()->keyBy('production_batch_id');
  $multiLotPosted=DB::table('finance_historical_fifo_multi_recoveries as recovery')
   ->join('currencies as currency','currency.id','=','recovery.currency_id')
   ->whereIn('recovery.production_batch_id',$ids)
   ->where('recovery.status','posted')
   ->select('recovery.id','recovery.production_batch_id',
       'recovery.source_material_total as original_material_cost',
       'currency.code as historical_currency_code')
   ->get();
  foreach($multiLotPosted as $recovery)
   $historicRecoveries->put($recovery->production_batch_id,$recovery);
  $wastes=DB::table('production_material_wastes as waste')
   ->join('production_order_materials as item',
      'item.id','=','waste.production_order_material_id')
   ->leftJoin('production_waste_financial_reviews as review',
      'review.production_material_waste_id','=','waste.id')
   ->leftJoin('production_abnormal_waste_cost_reviews as approved_cost',
      'approved_cost.production_material_waste_id','=','waste.id')
   ->leftJoin('currencies as source_currency',
      'source_currency.id','=','approved_cost.currency_id')
   ->whereIn('item.production_order_id',$batches->pluck('production_order_id'))
   ->select('item.production_order_id','review.treatment',
      'approved_cost.id as approved_cost_id',
      'approved_cost.source_cost_amount',
      'source_currency.code as source_currency_code')->get()
   ->groupBy('production_order_id');
  $result=collect();
  foreach($batches as $batch){
   $costs=$batch->costs;
   $overheadRows=$overhead->get($batch->id,collect());
   $sourcePosting=$capitalized->get($batch->id);
   $historicSource=$historicRecoveries->get($batch->id);
   $pending=$overheadRows->where('status','submitted')->count();
   $wasteRows=$wastes->get($batch->production_order_id,collect());
   $unreviewedWaste=$wasteRows->filter(fn($item)=>$item->treatment===null)->count();
   $unresolvedWaste=$wasteRows->filter(fn($row)=>
     $row->treatment==='rework_required' ||
     ($row->treatment==='abnormal_loss' && !$row->approved_cost_id))->count();
   $hasUnvalued=$costs->isEmpty() || $costs->contains(function($c){
     return $c->material_cost===null ||
       BigDecimal::of((string)$c->unvalued_quantity)->isGreaterThan(0);
   });
   $currencyCodes=$costs->pluck('currency_code')->filter()
    ->merge($overheadRows->where('status','approved')->pluck('currency_code'))
    ->merge($wasteRows->pluck('source_currency_code')->filter())
    ->merge($historicSource?collect([$historicSource->historical_currency_code]):collect())
    ->unique()->values();
   if($currencyCodes->isEmpty())$currencyCodes=collect([null]);
   $mixedCurrency=$currencyCodes->count()>1;
   foreach($currencyCodes as $code){
    $material=BigDecimal::zero();$overheadTotal=BigDecimal::zero();
    $abnormalTotal=BigDecimal::zero();
    foreach($wasteRows as $waste){
     if($waste->treatment==='abnormal_loss' &&
       $waste->approved_cost_id &&
       $waste->source_currency_code===$code){
      $abnormalTotal=$abnormalTotal->plus((string)$waste->source_cost_amount);
     }
    }
    $hasMaterialCurrency=false;
    foreach($costs->where('currency_code',$code) as $cost){
     if($cost->material_cost!==null){
       $material=$material->plus((string)$cost->material_cost);
       $hasMaterialCurrency=true;
     }
    }
    if($historicSource && $sourcePosting &&
       $historicSource->historical_currency_code===$code &&
       !$hasMaterialCurrency){
      $material=$material->plus((string)$historicSource->original_material_cost);
      $hasMaterialCurrency=true;
    }
    foreach($overheadRows as $allocation){
     if($allocation->status==='approved'&&$allocation->currency_code===$code)
      $overheadTotal=$overheadTotal->plus((string)$allocation->amount);
    }
    $eligibleValue=$material->plus($overheadTotal)->minus($abnormalTotal);
    $complete=$batch->status==='released' &&
      $batch->accepted_packages>0 && $hasMaterialCurrency &&
      !$hasUnvalued && !$mixedCurrency && $pending===0 &&
      $unreviewedWaste===0 && $unresolvedWaste===0 && $eligibleValue->isPositive();
    $costPerPackage=$complete?
      (string)$eligibleValue
       ->dividedBy((string)$batch->accepted_packages,8,RoundingMode::HalfUp):null;
    $warning=match(true){
      $unreviewedWaste>0=>'Recorded material waste requires independent finance classification',
      $unresolvedWaste>0=>'Abnormal production waste without exact FIFO approval, or unresolved rework',
      $historicSource!==null && $sourcePosting!==null=>
       'Historical original FIFO source-backed raw/WIP/finished/COGS financial recovery posted; original unvalued material snapshot preserved for audit',
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
     'abnormal_waste_source_cost'=>(string)$abnormalTotal->toScale(4,RoundingMode::HalfUp),
     'pending_overheads'=>$pending,
     'unreviewed_waste'=>$unreviewedWaste,
     'unresolved_waste'=>$unresolvedWaste,
     'has_unvalued'=>$hasUnvalued,
     'mixed_currency'=>$mixedCurrency,'ready'=>$complete,
     'cost_per_package'=>$costPerPackage,
     'gl_posted'=>$sourcePosting!==null,
     'historical_production_fifo_recovery_id'=>$historicSource?->id,
     'historical_original_cost_recovery'=>$historicSource!==null,
     'gl_currency_id'=>$sourcePosting?->currency_id,
     'gl_accounted_cost'=>$sourcePosting?->total_amount,
     'warning'=>$warning,
    ]);
   }
  }
  return $result;
 }
}