<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Models\InventoryStockCount;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryStockCountService
{
    public function __construct(private readonly InventoryAdjustmentService $adjustments) {}

    /**
     * Capture an immutable on-hand snapshot of the explicitly counted materials.
     * A count does not move stock until reviewed and approved.
     */
    public function create(array $data, User $user): InventoryStockCount
    {
        return DB::transaction(function () use ($data, $user) {
            if (InventoryStockCount::where('submission_token', $data['submission_token'])->exists()) {
                throw ValidationException::withMessages(['submission_token' => 'This stock count has already been submitted.']);
            }

            $quantities = [];
            foreach ($data['quantities'] as $materialId => $value) {
                if (! ctype_digit((string) $materialId)) {
                    throw ValidationException::withMessages(['quantities' => 'Invalid material identifier.']);
                }
                $quantities[(int) $materialId] = BigDecimal::of((string) $value)->toScale(6);
            }

            $materials = InventoryMaterial::query()->whereIn('id', array_keys($quantities))
                ->orderBy('id')->lockForUpdate()->get();
            if ($materials->count() !== count($quantities) || $materials->contains(fn ($m) => ! $m->is_active)) {
                throw ValidationException::withMessages(['quantities' => 'Only existing active materials can be counted.']);
            }

            $warehouseId=isset($data['company_warehouse_id']) && $data['company_warehouse_id'] !== ''
                ?(int)$data['company_warehouse_id']:null;
            if($warehouseId && !CompanyWarehouse::whereKey($warehouseId)->where('is_active',true)->exists()){
                throw ValidationException::withMessages(['company_warehouse_id'=>'Choose an active warehouse.']);
            }
            $balanceRows=DB::table('inventory_warehouse_balances')
                ->whereIn('inventory_material_id',$materials->pluck('id'))
                ->lockForUpdate()->get();
            $warehouseBalances=$balanceRows->groupBy('inventory_material_id');
            $count = InventoryStockCount::create([
                // The column is 32 characters; a full UUID placeholder (40) is rejected by
                // MySQL strict mode. 28 hex characters keep the placeholder unique.
                'number' => 'TMP-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 28),
                'submission_token' => $data['submission_token'],
                'counted_on' => $data['counted_on'],
                'status' => 'draft', 'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'company_warehouse_id'=>$warehouseId,
                'count_scope'=>$warehouseId?'warehouse':'unassigned',
            ]);

            foreach ($materials as $material) {
                $totalAllocated=BigDecimal::zero();
                $selected=BigDecimal::zero();
                foreach($warehouseBalances->get($material->id,collect()) as $balanceRow){
                    $totalAllocated=$totalAllocated->plus((string)$balanceRow->quantity);
                    if($warehouseId && (int)$balanceRow->company_warehouse_id===$warehouseId){
                        $selected=BigDecimal::of((string)$balanceRow->quantity);
                    }
                }
                $global=BigDecimal::of((string)$material->on_hand_quantity);
                if($totalAllocated->isGreaterThan($global)){
                    throw ValidationException::withMessages(['quantities'=>'Warehouse stock allocations exceed the company stock balance for '.$material->sku.'. Reconcile before counting.']);
                }
                $snapshot=$warehouseId?$selected:$global->minus($totalAllocated);
                $count->lines()->create([
                    'inventory_material_id' => $material->id,
                    'expected_quantity' => (string) $snapshot->toScale(6),
                    'counted_quantity' => (string) $quantities[$material->id],
                    'idempotency_key' => (string) Str::uuid(),
                ]);
            }
            $count->update(['number' => 'CNT-'.str_pad((string) $count->id, 6, '0', STR_PAD_LEFT)]);
            ActivityLog::record('inventory.count.created', 'inventory_stock_count', $count->id,
                'Physical stock count saved for review: '.$count->number, ['lines' => $materials->count()]);

            return $count;
        });
    }

    /**
     * Fail the ENTIRE approval if any source balance has changed or a negative
     * adjustment would consume a production reservation. Do not invent costs
     * for positive count discrepancies.
     */
    public function approve(InventoryStockCount $count, User $user): InventoryStockCount
    {
        return DB::transaction(function () use ($count, $user) {
            $locked = InventoryStockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['count' => 'This physical count has already been approved.']);
            }

            $lines = $locked->lines()->with('material')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['count' => 'An empty count cannot be approved.']);
            }

            foreach ($lines as $line) {
                $material = InventoryMaterial::query()->whereKey($line->inventory_material_id)->lockForUpdate()->firstOrFail();
                $expected = BigDecimal::of((string) $line->expected_quantity);
                $global=BigDecimal::of((string)$material->on_hand_quantity);
                $actual=$global;
                if($locked->count_scope!=='legacy_company'){
                    $balances=DB::table('inventory_warehouse_balances')
                        ->where('inventory_material_id',$material->id)->lockForUpdate()->get();
                    $allocated=BigDecimal::zero();
                    $selected=BigDecimal::zero();
                    foreach($balances as $balance){
                        $allocated=$allocated->plus((string)$balance->quantity);
                        if($locked->company_warehouse_id &&
                            (int)$balance->company_warehouse_id===(int)$locked->company_warehouse_id){
                            $selected=BigDecimal::of((string)$balance->quantity);
                        }
                    }
                    if($allocated->isGreaterThan($global)){
                        throw ValidationException::withMessages(['count'=>'Warehouse stock exceeds global stock for '.$material->sku.'.']);
                    }
                    $actual=$locked->count_scope==='warehouse'?$selected:$global->minus($allocated);
                }
                if (! $material->is_active || ! $actual->isEqualTo($expected)) {
                    throw ValidationException::withMessages([
                        'count' => 'Stock changed after counting material '.$material->sku.'. Record a new count using the current balance.',
                    ]);
                }

                $difference = BigDecimal::of((string) $line->counted_quantity)->minus($actual);
                if ($difference->isZero()) {
                    continue;
                }

                $increase = $difference->isGreaterThan(0);
                $quantity = $increase ? $difference : $difference->negated();
                $adjustment = $this->adjustments->post($material, [
                    'direction' => $increase ? 'adjustment_in' : 'adjustment_out',
                    'quantity' => (string) $quantity->toScale(6),
                    'occurred_on' => $locked->counted_on->toDateString(),
                    'reason' => 'Approved physical count '.$locked->number.
                        ($locked->notes ? ': '.Str::limit($locked->notes, 400) : ''),
                    'idempotency_key' => $line->idempotency_key,
                    'company_warehouse_id'=>$locked->count_scope==='warehouse'?$locked->company_warehouse_id:null,
                ], $user);

                $line->update(['inventory_stock_adjustment_id' => $adjustment->id]);
            }

            $locked->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
            ActivityLog::record('inventory.count.approved', 'inventory_stock_count', $locked->id,
                'Stock count approved and discrepancies posted: '.$locked->number);

            return $locked;
        });
    }
}
