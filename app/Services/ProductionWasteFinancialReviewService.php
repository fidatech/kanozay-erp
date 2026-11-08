<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductionMaterialWaste;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A waste row records a physical classification of issued material, never a
 * second stock issue. Finance determines whether absorbing its cost in accepted
 * output is permitted; abnormal and rework losses remain quarantined from
 * inventory capitalization until an explicit source-cost policy is approved.
 */
class ProductionWasteFinancialReviewService
{
    public function review(int $wasteId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($wasteId,$data,$actorId){
            $waste=ProductionMaterialWaste::whereKey($wasteId)
                ->lockForUpdate()->firstOrFail();
            if((int)$waste->recorded_by===$actorId){
                throw ValidationException::withMessages([
                    'waste'=>'The employee who recorded the production loss cannot approve its financial classification.',
                ]);
            }
            $line=$waste->materialLine;
            $order=DB::table('production_orders')
                ->where('id',$line->production_order_id)
                ->lockForUpdate()->first();
            abort_unless($order,404);
            $existing=DB::table('production_waste_financial_reviews')
                ->where('production_material_waste_id',$wasteId)->first();
            if($existing){
                if($existing->treatment===$data['treatment'] &&
                    $existing->approval_reference===trim($data['approval_reference']) &&
                    $existing->review_notes===trim($data['review_notes'])){
                    return $existing;
                }
                throw ValidationException::withMessages([
                    'waste'=>'An original financial waste classification is immutable. An independently approved correction document is needed to change it.',
                ]);
            }
            $batch=DB::table('production_batches')
                ->where('production_order_id',$order->id)->first();
            if($batch && (DB::table('production_batch_cost_postings')
                ->where('production_batch_id',$batch->id)->exists() ||
                DB::table('finished_good_dispatch_lines')
                ->where('production_batch_id',$batch->id)->exists())){
                throw ValidationException::withMessages([
                    'waste'=>'This batch already has finished-inventory capitalization or a physical sale. Review historic source cost and post an independently authorized correction, not a retrospective waste treatment change.',
                ]);
            }
            if($data['treatment']==='normal_process_loss' &&
               trim($data['review_notes'])===''){
                throw ValidationException::withMessages([
                    'review_notes'=>'Specify the approved production loss policy and why this quantity qualifies.',
                ]);
            }
            $id=DB::table('production_waste_financial_reviews')->insertGetId([
                'production_material_waste_id'=>$wasteId,
                'treatment'=>$data['treatment'],
                'approval_reference'=>trim($data['approval_reference']),
                'review_notes'=>trim($data['review_notes']),
                'reviewed_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('manufacturing.material_waste.financially_reviewed',
                'production_material_waste',$wasteId,
                'Issued material loss classified for financial treatment; no stock or journal was posted.',[
                    'treatment'=>$data['treatment'],
                    'production_order_id'=>$order->id,
                    'approval_reference'=>$data['approval_reference'],
                ]);
            return DB::table('production_waste_financial_reviews')
                ->where('id',$id)->first();
        });
    }

    public function assertReleasedCostTreatment(int $orderId):void
    {
        $reviews=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as line',
                'line.id','=','waste.production_order_material_id')
            ->leftJoin('production_waste_financial_reviews as review',
                'review.production_material_waste_id','=','waste.id')
            ->where('line.production_order_id',$orderId)
            ->get(['waste.id','waste.quantity','review.treatment']);
        foreach($reviews as $entry){
            if(!$entry->treatment){
                throw ValidationException::withMessages([
                    'waste'=>'A recorded production material loss has no independent financial classification. Review all material waste before QC warehouse release or cost capitalization.',
                ]);
            }
            if($entry->treatment==='abnormal_loss'){
                throw ValidationException::withMessages([
                    'waste'=>'An abnormal production loss cannot be included automatically in saleable finished-goods inventory. A reviewed FIFO source-cost allocation and separate expense recognition are required before capitalization.',
                ]);
            }
            if($entry->treatment==='rework_required'){
                throw ValidationException::withMessages([
                    'waste'=>'A production material loss still requires rework. Record its approved physical and financial disposition before releasing saleable finished goods.',
                ]);
            }
            if($entry->treatment!=='normal_process_loss'){
                throw ValidationException::withMessages([
                    'waste'=>'Unknown financial waste treatment. Reconcile the signed production source.',
                ]);
            }
        }
    }

    public function summaryForOrder(int $orderId):array
    {
        $source=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as line',
                'line.id','=','waste.production_order_material_id')
            ->leftJoin('production_waste_financial_reviews as review',
                'review.production_material_waste_id','=','waste.id')
            ->where('line.production_order_id',$orderId)
            ->select('waste.id','review.treatment','review.approval_reference',
                'review.review_notes','review.reviewed_by')->get();
        return [
            'reviews'=>$source->keyBy('id'),
            'pending'=>$source->filter(fn($row)=>!$row->treatment)->count(),
            'abnormal'=>$source->where('treatment','abnormal_loss')->count(),
            'rework'=>$source->where('treatment','rework_required')->count(),
        ];
    }
}
