<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinishedGood;
use App\Models\FinishedGoodDispatch;
use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinishedGoodDispatchService
{
    public function availablePackages(ProductionBatch $batch): int
    {
        $dispatched = (int) $batch->dispatchLines()->sum('quantity_packages');
        $restockedReturns = (int) DB::table('sales_credit_note_lines')
            ->where('production_batch_id', $batch->id)->where('restocked', true)->sum('quantity_packages');
        return max(0, (int) $batch->accepted_packages + $restockedReturns - $dispatched);
    }

    public function post(int $goodId, int $batchId, int $quantity, array $data, int $actorId): FinishedGoodDispatch
    {
        return DB::transaction(function () use ($goodId, $batchId, $quantity, $data, $actorId) {
            $existing = FinishedGoodDispatch::where('submission_token', $data['submission_token'])->first();
            if ($existing) return $existing->load('lines');
            if ($quantity < 1) $this->reject('Dispatch quantity must be at least one package.');

            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($batch->status !== 'released' || (int) $batch->finished_good_id !== $goodId) {
                $this->reject('Choose a released batch for this finished good.');
            }
            if ($batch->released_at && $data['dispatch_date'] < $batch->released_at->toDateString()) {
                $this->reject('Dispatch date cannot precede the batch warehouse release date.');
            }
            $good = FinishedGood::query()->lockForUpdate()->findOrFail($goodId);
            if (! $good->is_active) $this->reject('Inactive finished goods cannot be dispatched.');

            $available = $this->availablePackages($batch);
            if ($quantity > $available) $this->reject('Dispatch quantity exceeds the remaining packages in this batch.');
            if ($quantity > $good->on_hand_packages) $this->reject('Dispatch quantity exceeds the warehouse balance.');

            DocumentSequence::firstOrCreate(['document_type' => 'finished_good_dispatch'], [
                'prefix' => 'DN-', 'next_number' => 1, 'padding' => 6, 'is_active' => true,
            ]);
            $dispatch = FinishedGoodDispatch::create([
                'number' => DocumentSequence::issue('finished_good_dispatch'),
                'submission_token' => $data['submission_token'],
                'dispatch_date' => $data['dispatch_date'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            $dispatch->lines()->create([
                'finished_good_id' => $good->id,
                'production_batch_id' => $batch->id,
                'quantity_packages' => $quantity,
            ]);
            $good->forceFill(['on_hand_packages' => $good->on_hand_packages - $quantity])->save();
            ActivityLog::create([
                'actor_id' => $actorId,
                'action' => 'finished_good.dispatched',
                'subject_type' => 'finished_good_dispatch',
                'subject_id' => $dispatch->id,
                'description' => $dispatch->number.' dispatched '.$quantity.' packages',
            ]);

            return $dispatch->load('lines');
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['dispatch' => $message]);
    }
}
