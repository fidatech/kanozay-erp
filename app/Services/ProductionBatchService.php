<?php

namespace App\Services;

use App\Models\FinishedGood;
use App\Models\ActivityLog;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryStockMovement;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionBatchService
{
    public function submit(ProductionOrder $order, FinishedGood $good, int $reported, ?string $notes, int $actor): ProductionBatch
    {
        return DB::transaction(function () use ($order, $good, $reported, $notes, $actor) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertStatus($order, 'issued');
            if(DB::table('production_work_steps')->where('production_order_id',$order->id)
                ->where('status','!=','completed')->exists())
                $this->reject('Complete each approved production-routing work step before submitting the batch to QC.');
            if ($order->batch()->exists()) {
                $this->reject('This production order already has a batch.');
            }
            // Use the specification frozen when this production plan was created.
            // Legacy orders without snapshots fall back to the linked recipe.
            $unit = $order->planned_package_unit;
            $weight = $order->planned_package_weight_kg;
            $productCode = $order->planned_product_code;
            $good = FinishedGood::query()->findOrFail($good->id);
            if (! $weight || ! $unit || ! $good->is_active || $good->package_unit !== $unit
                || BigDecimal::of((string) $good->package_weight_kg)->compareTo(BigDecimal::of((string) $weight)) !== 0
                || (filled($productCode) && $good->sku !== $productCode)) {
                $this->reject('Finished good SKU, unit and package weight must match the saved production plan.');
            }
            if ($reported < 1 || $reported > $order->package_count) {
                $this->reject('Reported packages must be between one and the planned package count.');
            }
            // Packaging quantities are frozen with the approved recipe. A
            // batch cannot report more finished packages than the issued
            // packaging can physically support. Extra loss remains recorded
            // as waste and is already counted in issued quantities.
            foreach ($order->materials()->where('is_packaging',true)->get() as $packaging) {
                $required = BigDecimal::of((string)$packaging->quantity_per_package)
                    ->multipliedBy((string)$reported);
                if (BigDecimal::of((string)$packaging->issued_quantity)->isLessThan($required)) {
                    $this->reject('Insufficient issued packaging material '.$packaging->material_name.
                        ' for '.$reported.' reported packages. Reconcile issued quantities before batch submission.');
                }
            }
            $batch = $order->batch()->create([
                'finished_good_id' => $good->id,
                'batch_number' => 'BATCH-PENDING-'.bin2hex(random_bytes(8)),
                'reported_packages' => $reported,
                'notes' => $notes,
                'submitted_by' => $actor,
            ]);
            $batch->forceFill(['batch_number' => 'BAT-'.str_pad((string) $batch->id, 7, '0', STR_PAD_LEFT)])->save();
            $order->update(['status' => 'pending_qc']);
            ActivityLog::record('production.batch.submitted','production_batch',$batch->id,
                'Actual finished packages submitted for laboratory review.',
                ['order_id'=>$order->id,'reported_packages'=>$reported,'finished_good_id'=>$good->id]);
            return $batch;
        });
    }

    public function recordCheck(ProductionBatch $batch, array $data, int $actor): void
    {
        DB::transaction(function () use ($batch, $data, $actor) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertStatus($batch, 'pending_qc');
            if ($batch->checks()->where('test_name', $data['test_name'])->exists()) {
                $this->reject('This laboratory test has already been recorded for the batch.');
            }
            $batch->checks()->create([
                'test_name' => $data['test_name'],
                'specification' => $data['specification'] ?? null,
                'observation' => $data['observation'] ?? null,
                'result' => $data['result'],
                'recorded_by' => $actor,
            ]);
            ActivityLog::record('production.batch.qc_check','production_batch',$batch->id,
                'Batch laboratory check recorded.',[
                    'test_name'=>$data['test_name'],'result'=>$data['result'],
                ]);
        });
    }

    public function decide(ProductionBatch $batch, string $decision, int $rejected, string $notes, int $actor): void
    {
        DB::transaction(function () use ($batch, $decision, $rejected, $notes, $actor) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertStatus($batch, 'pending_qc');
            // A separately registered QC sample must be independently approved.
            // Existing free-text batch checks still apply when no sample is registered.
            if($decision==='approve' && DB::table('quality_samples')
                ->where('production_batch_id',$batch->id)->where('status','!=','approved')->exists()){
                $this->reject('One or more registered QC samples are pending or rejected.');
            }
            $checks = $batch->checks()->get();
            if ($checks->isEmpty()) {
                $this->reject('Record at least one laboratory test before making a QC decision.');
            }
            $failed = $checks->contains('result', 'fail');
            if ($decision === 'approve') {
                if ($failed) {
                    $this->reject('A failed laboratory check prevents approval. Reject the batch for review.');
                }
                if ($rejected < 0 || $rejected >= $batch->reported_packages) {
                    $this->reject('Approved batches must have at least one accepted package.');
                }
                $batch->forceFill([
                    'accepted_packages' => $batch->reported_packages - $rejected,
                    'rejected_packages' => $rejected,
                    'status' => 'qc_approved',
                    'qc_notes' => $notes,
                    'decided_by' => $actor,
                    'decided_at' => now(),
                ])->save();
                $batch->order()->update(['status' => 'qc_approved']);
                ActivityLog::record('production.batch.qc_approved','production_batch',$batch->id,
                    'Laboratory accepted reported batch packages for warehouse release.',[
                        'accepted_packages'=>$batch->reported_packages-$rejected,
                        'rejected_packages'=>$rejected,
                    ]);
            } elseif ($decision === 'reject') {
                if (! $failed) {
                    $this->reject('A failed laboratory check is required to reject a batch.');
                }
                $batch->forceFill([
                    'accepted_packages' => 0,
                    'rejected_packages' => $batch->reported_packages,
                    'status' => 'qc_rejected',
                    'qc_notes' => $notes,
                    'decided_by' => $actor,
                    'decided_at' => now(),
                ])->save();
                $batch->order()->update(['status' => 'qc_rejected']);
                ActivityLog::record('production.batch.qc_rejected','production_batch',$batch->id,
                    'Laboratory rejected the batch; no finished goods may be released.',[
                        'rejected_packages'=>$batch->reported_packages,
                    ]);
            } else {
                $this->reject('Invalid laboratory decision.');
            }
        });
    }

    public function release(ProductionBatch $batch, string $date, int $actor, ?int $warehouseId=null): void
    {
        DB::transaction(function () use ($batch, $date, $actor, $warehouseId) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertStatus($batch, 'qc_approved');
            if ($batch->receipt()->exists() || $batch->accepted_packages < 1) {
                $this->reject('This batch has already been released or has no accepted packages.');
            }
            $good = FinishedGood::query()->lockForUpdate()->findOrFail($batch->finished_good_id);
            if (! $good->is_active) {
                $this->reject('Inactive finished goods cannot receive stock.');
            }
            if ($good->on_hand_packages > PHP_INT_MAX - $batch->accepted_packages) {
                $this->reject('Finished goods balance exceeds the supported range.');
            }
            // QC can change between material issue and finished-product
            // release. Never release packages whose input FIFO sources have
            // since become held or whose posted usage cannot be traced.
            $this->assertSourceLotsReleased($batch);
            app(ProductionCostService::class)->snapshot($batch);
            $good->movements()->create([
                'production_batch_id' => $batch->id,
                'company_warehouse_id' => $warehouseId,
                'type' => 'production_receipt',
                'quantity_packages' => $batch->accepted_packages,
                'occurred_on' => $date,
                'created_by' => $actor,
            ]);
            $good->forceFill(['on_hand_packages' => $good->on_hand_packages + $batch->accepted_packages])->save();
            app(FinishedGoodWarehouseService::class)->receipt($batch,$warehouseId,(int)$batch->accepted_packages,$date,$actor);
            $batch->forceFill(['status' => 'released', 'released_by' => $actor, 'released_at' => now()])->save();
            $batch->order()->update(['status' => 'completed']);
            ActivityLog::record('production.batch.released','production_batch',$batch->id,
                'QC-approved finished packages released into the selected warehouse.',
                ['warehouse_id'=>$warehouseId,'packages'=>$batch->accepted_packages,
                 'released_on'=>$date,'cost_snapshot_rows'=>$batch->costs()->count()]);
        });
    }

    private function assertSourceLotsReleased(ProductionBatch $batch): void
    {
        $materialLineIds=$batch->order->materials()->pluck('id');
        if($materialLineIds->isEmpty()){
            $this->reject('Production batch has no frozen material requirements to trace.');
        }
        $movements=InventoryStockMovement::where('reference_type','production_order_material')
            ->whereIn('reference_id',$materialLineIds)->orderBy('id')->get();
        if($movements->isEmpty()){
            $this->reject('No posted raw-material or packaging issues are linked to this production batch.');
        }
        $consumptions=InventoryCostConsumption::whereIn(
            'inventory_stock_movement_id',$movements->pluck('id')
        )->get()->groupBy('inventory_stock_movement_id');
        $lotIds=$consumptions->flatten()->pluck('inventory_cost_layer_id')->filter()->unique();
        $layers=InventoryCostLayer::whereIn('id',$lotIds)->get()->keyBy('id');
        $holds=app(QualityMaterialHoldService::class);
        foreach($movements as $movement){
            $records=$consumptions->get($movement->id,collect());
            $tracked=BigDecimal::zero();
            foreach($records as $record){
                $tracked=$tracked->plus((string)$record->quantity);
                if(!$record->inventory_cost_layer_id){
                    $this->reject('A production input issue has no verifiable FIFO source lot. Reconcile its historical provenance before warehouse release.');
                }
                $lot=$layers->get($record->inventory_cost_layer_id);
                if(!$lot || (int)$lot->inventory_material_id!==(int)$movement->inventory_material_id){
                    $this->reject('A production issue references a missing or mismatched source lot.');
                }
                if($holds->isLayerHeld($lot)){
                    $this->reject('Input material FIFO lot '.$lot->id.' is currently on QC hold. Review the source lot and finished batch before release.');
                }
            }
            if(!$tracked->isEqualTo((string)$movement->quantity)){
                $this->reject('Material FIFO consumption differs from the posted physical issue. Reconcile original inventory records.');
            }
        }
    }

    private function assertStatus(ProductionOrder|ProductionBatch $record, string $status): void
    {
        if ($record->status !== $status) {
            $this->reject('This action requires status '.$status.'.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production_batch' => $message]);
    }
}
