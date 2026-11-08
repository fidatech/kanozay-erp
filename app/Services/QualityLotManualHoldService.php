<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualityLotManualHoldService
{
    public function hold(int $layerId,array $data,int $actorId): object
    {
        return DB::transaction(function() use ($layerId,$data,$actorId){
            $source=InventoryCostLayer::findOrFail($layerId);
            InventoryMaterial::whereKey($source->inventory_material_id)->lockForUpdate()->firstOrFail();
            $layer=InventoryCostLayer::whereKey($layerId)->lockForUpdate()->firstOrFail();
            if($layer->source_type!=='goods_receipt_line'){
                throw ValidationException::withMessages([
                    'inventory_cost_layer_id'=>'This hold workflow is limited to posted incoming GRN FIFO lots.',
                ]);
            }
            $existing=DB::table('quality_material_lot_holds')
                ->where('submission_token',$data['submission_token'])->first();
            if($existing){
                if((int)$existing->inventory_cost_layer_id===$layerId &&
                    $existing->reference===$data['reference'] &&
                    $existing->reason===$data['reason'])return $existing;
                throw ValidationException::withMessages([
                    'submission_token'=>'A different hold already uses this submission token.',
                ]);
            }
            if(DB::table('quality_material_lot_holds')->where('inventory_cost_layer_id',$layerId)
                ->where('status','held')->exists()){
                throw ValidationException::withMessages([
                    'inventory_cost_layer_id'=>'The source lot is already subject to an active manual hold.',
                ]);
            }
            $id=DB::table('quality_material_lot_holds')->insertGetId([
                'inventory_cost_layer_id'=>$layerId,
                'submission_token'=>$data['submission_token'],
                'status'=>'held','reference'=>trim($data['reference']),
                'reason'=>trim($data['reason']),'held_by'=>$actorId,'held_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('quality_material_lot_hold_events')->insert([
                'quality_material_lot_hold_id'=>$id,'action'=>'hold',
                'reference'=>$data['reference'],'reason'=>$data['reason'],
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('quality.incoming_lot.held','inventory_cost_layer',$layerId,
                'Manual incoming-material QC hold placed on source FIFO lot.',
                ['hold_id'=>$id,'reference'=>$data['reference']]);
            return DB::table('quality_material_lot_holds')->where('id',$id)->first();
        });
    }

    public function release(int $holdId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($holdId,$data,$actorId){
            $source=DB::table('quality_material_lot_holds')->where('id',$holdId)->first();
            abort_unless($source,404);
            $lot=InventoryCostLayer::findOrFail($source->inventory_cost_layer_id);
            InventoryMaterial::whereKey($lot->inventory_material_id)->lockForUpdate()->firstOrFail();
            $hold=DB::table('quality_material_lot_holds')
                ->where('id',$holdId)->lockForUpdate()->first();
            if($hold->status!=='held'){
                throw ValidationException::withMessages(['hold'=>'This manual QC hold is already closed.']);
            }
            if((int)$hold->held_by===$actorId){
                throw ValidationException::withMessages([
                    'hold'=>'A second authorized reviewer must release this lot hold.',
                ]);
            }
            $latest=DB::table('quality_samples')
                ->where('goods_receipt_line_id',$lot->source_id)
                ->orderByDesc('id')->first();
            $activeSpecifications=DB::table('quality_specifications')
                ->where('subject_type','material')
                ->where('subject_id',$lot->inventory_material_id)
                ->where('is_active',true)->exists();
            if(($latest && $latest->status!=='approved') ||
                (!$latest && $activeSpecifications)){
                throw ValidationException::withMessages([
                    'hold'=>'A required incoming-lot sample remains pending or rejected. Approve the laboratory sample before clearing the manual hold.',
                ]);
            }
            DB::table('quality_material_lot_holds')->where('id',$holdId)->update([
                'status'=>'released','released_by'=>$actorId,'released_at'=>now(),
                'release_reference'=>trim($data['reference']),
                'release_reason'=>trim($data['reason']),'updated_at'=>now(),
            ]);
            DB::table('quality_material_lot_hold_events')->insert([
                'quality_material_lot_hold_id'=>$holdId,'action'=>'release',
                'reference'=>$data['reference'],'reason'=>$data['reason'],
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('quality.incoming_lot.hold_released','inventory_cost_layer',
                $lot->id,'Independent review removed a manual incoming-material QC hold.',
                ['hold_id'=>$holdId,'reference'=>$data['reference']]);
            return DB::table('quality_material_lot_holds')->where('id',$holdId)->first();
        });
    }
}
