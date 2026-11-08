<?php

namespace App\Services;

use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch QC uses the latest independently reviewed laboratory sample, when
 * specifications or samples exist, and never grants release from a form-only
 * pass result while required source-linked tests are missing.
 */
class FinishedBatchQualityGate
{
    public function assertApproved(ProductionBatch $batch): void
    {
        $active=DB::table('quality_specifications')->where([
            'subject_type'=>'finished_good','subject_id'=>$batch->finished_good_id,
            'is_active'=>true,
        ])->pluck('id')->map(fn($id)=>(int)$id)->all();
        $sample=DB::table('quality_samples')
            ->where('production_batch_id',$batch->id)->orderByDesc('id')->first();
        if(!$sample){
            if($active){
                throw ValidationException::withMessages([
                    'batch'=>'A formal QC sample with all approved product tests is required before batch acceptance or release.',
                ]);
            }
            return; // No product specification approved by the company.
        }
        if($sample->status!=='approved'){
            throw ValidationException::withMessages([
                'batch'=>'The most recent laboratory sample has not been independently approved.',
            ]);
        }
        $frozen=DB::table('quality_sample_required_specifications')
            ->where('quality_sample_id',$sample->id)->pluck('quality_specification_id')
            ->map(fn($id)=>(int)$id)->all();
        $required=$frozen?:$active;
        if(!$required){
            throw ValidationException::withMessages([
                'batch'=>'The laboratory sample has no verifiable required product tests.',
            ]);
        }
        $passed=DB::table('quality_sample_results')
            ->where('quality_sample_id',$sample->id)
            ->whereIn('quality_specification_id',$required)
            ->where('outcome','pass')->pluck('quality_specification_id')
            ->map(fn($id)=>(int)$id)->all();
        if(array_diff($required,$passed)){
            throw ValidationException::withMessages([
                'batch'=>'The formal laboratory sample does not pass every required test assigned to it.',
            ]);
        }
    }
}
