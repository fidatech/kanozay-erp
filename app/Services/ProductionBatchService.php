<?php

namespace App\Services;

use App\Models\FinishedGood;
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
            $batch = $order->batch()->create([
                'finished_good_id' => $good->id,
                'batch_number' => 'BATCH-PENDING-'.bin2hex(random_bytes(8)),
                'reported_packages' => $reported,
                'notes' => $notes,
                'submitted_by' => $actor,
            ]);
            $batch->forceFill(['batch_number' => 'BAT-'.str_pad((string) $batch->id, 7, '0', STR_PAD_LEFT)])->save();
            $order->update(['status' => 'pending_qc']);

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
        });
    }

    public function decide(ProductionBatch $batch, string $decision, int $rejected, string $notes, int $actor): void
    {
        DB::transaction(function () use ($batch, $decision, $rejected, $notes, $actor) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertStatus($batch, 'pending_qc');
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
            } else {
                $this->reject('Invalid laboratory decision.');
            }
        });
    }

    public function release(ProductionBatch $batch, string $date, int $actor): void
    {
        DB::transaction(function () use ($batch, $date, $actor) {
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
            $good->movements()->create([
                'production_batch_id' => $batch->id,
                'type' => 'production_receipt',
                'quantity_packages' => $batch->accepted_packages,
                'occurred_on' => $date,
                'created_by' => $actor,
            ]);
            $good->forceFill(['on_hand_packages' => $good->on_hand_packages + $batch->accepted_packages])->save();
            $batch->forceFill(['status' => 'released', 'released_by' => $actor, 'released_at' => now()])->save();
            $batch->order()->update(['status' => 'completed']);
        });
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
