<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseStockService
{
    private function amount(string $raw):BigDecimal
    {
        if(!preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/',$raw))
            throw ValidationException::withMessages(['quantity'=>'Use a positive quantity with up to six decimal places.']);
        $number=BigDecimal::of($raw);
        if(!$number->isPositive())throw ValidationException::withMessages(['quantity'=>'Quantity must be positive.']);
        return $number->toScale(6);
    }

    /**
     * Allocate legacy/unassigned company-wide stock to a physical warehouse.
     * This does NOT change global on-hand or create a cost-layer entry.
     * Existing FIFO layer positions must be source-verified first; historical
     * unpositioned stock will not be silently attributed to an arbitrary lot.
     */
    public function allocate(InventoryMaterial $material, CompanyWarehouse $destination, array $data, User $actor):object
    {
        return $this->record($material,null,$destination,$data,$actor);
    }

    /**
     * Move stock already assigned to a warehouse. No duplicate global stock issue/receipt is posted.
     * Global production reservations are not warehouse-specific, so transfers are held during reservation.
     */
    public function transfer(InventoryMaterial $material,CompanyWarehouse $source,CompanyWarehouse $destination,array $data,User $actor):object
    {
        return $this->record($material,$source,$destination,$data,$actor);
    }

    private function record(InventoryMaterial $material,?CompanyWarehouse $source,CompanyWarehouse $destination,array $data,User $actor):object
    {
        return DB::transaction(function () use ($material,$source,$destination,$data,$actor) {
            $m=InventoryMaterial::whereKey($material->id)->lockForUpdate()->firstOrFail();
            if(!$m->is_active)throw ValidationException::withMessages(['material'=>'Inactive materials cannot be allocated or transferred.']);
            if($source && $source->id===$destination->id)
                throw ValidationException::withMessages(['destination_warehouse_id'=>'Source and destination must differ.']);

            $existing=DB::table('inventory_warehouse_movements')->where('idempotency_key',$data['idempotency_key'])->first();
            if($existing){
                $same=(int)$existing->inventory_material_id===$m->id
                    && (int)($existing->source_warehouse_id??0)===(int)($source?->id??0)
                    && (int)$existing->destination_warehouse_id===$destination->id
                    && $existing->occurred_on===$data['occurred_on']
                    && $existing->reason===trim($data['reason'])
                    && BigDecimal::of((string)$existing->quantity)->isEqualTo((string)$data['quantity']);
                if(!$same)throw ValidationException::withMessages(['idempotency_key'=>'Submission token already belongs to another movement.']);
                return $existing;
            }

            $ids=array_filter([$source?->id,$destination->id]);
            $locations=CompanyWarehouse::whereIn('id',$ids)->where('is_active',true)->lockForUpdate()->get()->keyBy('id');
            if($locations->count()!==count($ids))
                throw ValidationException::withMessages(['warehouse'=>'Both warehouses must be active.']);

            $qty=$this->amount((string)$data['quantity']);
            $current=DB::table('inventory_warehouse_balances')->where('inventory_material_id',$m->id)
                ->lockForUpdate()->get();
            $byWarehouse=$current->keyBy('company_warehouse_id');
            $total=BigDecimal::zero();
            foreach($current as $row)$total=$total->plus((string)$row->quantity);
            $global=BigDecimal::of((string)$m->on_hand_quantity);
            if($total->isGreaterThan($global))throw ValidationException::withMessages(['stock'=>'Warehouse stock exceeds company-wide stock. Reconcile before proceeding.']);

            if($source) {
                if(BigDecimal::of((string)$m->reserved_quantity)->isPositive())
                    throw ValidationException::withMessages(['stock'=>'Warehouse-specific reservations are not recorded yet. Clear production reservations before physical transfer.']);
                $sourceAmount=BigDecimal::of((string)($byWarehouse[$source->id]->quantity??'0'));
                if($sourceAmount->isLessThan($qty))
                    throw ValidationException::withMessages(['quantity'=>'Insufficient allocated stock in the source warehouse.']);
                DB::table('inventory_warehouse_balances')->where('inventory_material_id',$m->id)->where('company_warehouse_id',$source->id)
                    ->update(['quantity'=>(string)$sourceAmount->minus($qty)->toScale(6),'updated_at'=>now()]);
            } else {
                $unallocated=$global->minus($total);
                if($unallocated->isLessThan($qty))
                    throw ValidationException::withMessages(['quantity'=>'Allocation exceeds stock not yet assigned to a warehouse.']);
            }

            $destAmount=BigDecimal::of((string)($byWarehouse[$destination->id]->quantity??'0'))->plus($qty);
            if($byWarehouse->has($destination->id)) {
                DB::table('inventory_warehouse_balances')
                    ->where('inventory_material_id',$m->id)->where('company_warehouse_id',$destination->id)
                    ->update(['quantity'=>(string)$destAmount->toScale(6),'updated_at'=>now()]);
            } else {
                DB::table('inventory_warehouse_balances')->insert([
                    'inventory_material_id'=>$m->id,'company_warehouse_id'=>$destination->id,
                    'quantity'=>(string)$destAmount->toScale(6),'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $id=DB::table('inventory_warehouse_movements')->insertGetId([
                'inventory_material_id'=>$m->id,
                'source_warehouse_id'=>$source?->id,'destination_warehouse_id'=>$destination->id,
                'type'=>$source?'transfer':'initial_allocation','quantity'=>(string)$qty,
                'occurred_on'=>$data['occurred_on'],'reason'=>trim($data['reason']),
                'idempotency_key'=>$data['idempotency_key'],'created_by'=>$actor->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            // Move the exact FIFO lots from the source pool to the
            // destination in the same transaction as aggregate balances.
            // A missing/held legacy position rejects the entire operation.
            app(InventoryLotLocationService::class)->move(
                $m, $source?->id, $destination->id, $qty, $id, $data['occurred_on']
            );
            ActivityLog::record($source?'warehouse.transfer':'warehouse.stock.allocated','inventory_material',$m->id,
                ($source?'Stock transferred':'Unassigned stock allocated').' for '.$m->sku,
                ['movement_id'=>$id,'quantity'=>(string)$qty,'source_id'=>$source?->id,'destination_id'=>$destination->id]);
            return DB::table('inventory_warehouse_movements')->where('id',$id)->first();
        });
    }

    public function snapshot():array
    {
        $materials=InventoryMaterial::where('is_active',true)->orderBy('sku')->get();
        $warehouses=CompanyWarehouse::with('branch')->where('is_active',true)->orderBy('name')->get();
        $rows=DB::table('inventory_warehouse_balances')->get();
        // Positional lot totals are cost-layer provenance, independent of
        // aggregate warehouse balances. Differences are reconciliation work.
        $positionRows=DB::table('inventory_cost_layer_locations as p')
            ->join('inventory_cost_layers as l','l.id','=','p.inventory_cost_layer_id')
            ->select('l.inventory_material_id','p.location_key','p.quantity')->get();
        $verified=[];
        foreach($positionRows as $position){
            $materialId=$position->inventory_material_id;$location=$position->location_key;
            $verified[$materialId][$location]=($verified[$materialId][$location]??BigDecimal::zero())
                ->plus((string)$position->quantity);
        }
        $byMaterial=[];
        foreach($rows as $row)$byMaterial[$row->inventory_material_id][$row->company_warehouse_id]=$row->quantity;
        $data=[];
        foreach($materials as $m) {
            $sum=BigDecimal::zero();
            foreach($byMaterial[$m->id]??[] as $amount)$sum=$sum->plus((string)$amount);
            $unassigned=BigDecimal::of((string)$m->on_hand_quantity)->minus($sum);
            $locations=$verified[$m->id]??[];
            $verifiedTotal=BigDecimal::zero();
            foreach($locations as $amount)$verifiedTotal=$verifiedTotal->plus($amount);
            $locationMismatch=$unassigned->isNegative();
            $unassignedVerified=$locations['unassigned']??BigDecimal::zero();
            if($unassignedVerified->isGreaterThan($unassigned))$locationMismatch=true;
            foreach($byMaterial[$m->id]??[] as $warehouseId=>$physical){
                $located=$locations['wh:'.$warehouseId]??BigDecimal::zero();
                if($located->isGreaterThan((string)$physical))$locationMismatch=true;
            }
            if($verifiedTotal->isGreaterThan((string)$m->on_hand_quantity))$locationMismatch=true;
            $data[]=['material'=>$m,'warehouses'=>$byMaterial[$m->id]??[],
                'verified_lots'=>collect($locations)->map(fn($q)=>(string)$q->toScale(6))->all(),
                'verified_total'=>(string)$verifiedTotal->toScale(6),
                'unpositioned_physical'=>(string)BigDecimal::of((string)$m->on_hand_quantity)
                    ->minus($verifiedTotal)->toScale(6),
                'allocated'=>(string)$sum->toScale(6),'unallocated'=>(string)$unassigned->toScale(6),
                'reconcile'=>$locationMismatch?'mismatch':
                    ($verifiedTotal->isEqualTo((string)$m->on_hand_quantity)?'located':'pending')];
        }
        return compact('warehouses','data');
    }
}
