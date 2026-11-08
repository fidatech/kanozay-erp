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
        $releasedQuarantines=(int)DB::table('sales_return_quarantines')
            ->where('production_batch_id',$batch->id)->where('status','released')
            ->sum('quantity_packages');
        // Historic direct-restock flags must not double-count a newer QC
        // quarantine record that already credited the same physical stock.
        $restockedReturns = (int) DB::table('sales_credit_note_lines as credited')
            ->leftJoin('sales_return_quarantines as quarantined',
                'quarantined.sales_credit_note_line_id','=','credited.id')
            ->where('credited.production_batch_id',$batch->id)
            ->where('credited.restocked',true)
            ->whereNull('quarantined.id')->sum('credited.quantity_packages');
        return max(0, (int) $batch->accepted_packages + $restockedReturns + $releasedQuarantines - $dispatched);
    }

    public function post(int $goodId, int $batchId, int $quantity, array $data, int $actorId): FinishedGoodDispatch
    {
        return DB::transaction(function () use ($goodId, $batchId, $quantity, $data, $actorId) {
            $existing = FinishedGoodDispatch::where('submission_token', $data['submission_token'])->first();
            if ($existing){
                $line=$existing->lines()->first();
                if(!$line || (int)$line->finished_good_id!==$goodId ||
                    (int)$line->production_batch_id!==$batchId ||
                    (int)$line->quantity_packages!==$quantity ||
                    (int)($line->company_warehouse_id??0)!==(int)($data['company_warehouse_id']??0) ||
                    $existing->dispatch_date->toDateString()!==$data['dispatch_date'])
                    $this->reject('Dispatch token belongs to another batch movement.');
                return $existing->load('lines');
            }
            if ($quantity < 1) $this->reject('Dispatch quantity must be at least one package.');

            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($batch->status !== 'released' || (int) $batch->finished_good_id !== $goodId) {
                $this->reject('Choose a released batch for this finished good.');
            }
            if(DB::table('production_overhead_allocations')
                ->where('production_batch_id',$batch->id)
                ->where('status','submitted')->exists()){
                $this->reject('A production cost allocation is pending independent finance review. Approve or reject it before dispatch so the source cost cannot change after sale.');
            }
            app(FinishedGoodLotControlService::class)->assertDispatchAllowed($batch,$data['dispatch_date']);
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
            app(FinishedGoodWarehouseService::class)->dispatch(
                $batch,$data['company_warehouse_id']??null,$quantity,
                $data['dispatch_date'],$dispatch->number,$actorId,
                (string)\Illuminate\Support\Str::uuid()
            );
            $dispatch->lines()->create([
                'company_warehouse_id'=>$data['company_warehouse_id']??null,
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
