<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CompanyWarehouse;
use App\Models\GoodsReceipt;
use App\Models\InventoryMaterial;
use App\Models\PurchaseReturnRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    public function __construct(private readonly InventoryStockService $stock) {}

    public function remaining(GoodsReceipt $receipt):array
    {
        $lines=$receipt->lines()->get();
        $returnTotals=DB::table('purchase_return_record_lines as l')
            ->join('purchase_return_records as r','r.id','=','l.purchase_return_record_id')
            ->where('r.goods_receipt_id',$receipt->id)
            ->select('l.goods_receipt_line_id')->selectRaw('SUM(l.quantity) as used')
            ->groupBy('l.goods_receipt_line_id')->pluck('used','goods_receipt_line_id');
        $available=[];
        foreach($lines as $line){
            $balance=BigDecimal::of((string)$line->quantity)
                ->minus((string)($returnTotals[$line->id]??'0'));
            $available[$line->id]=(string)$balance->toScale(6);
        }
        return $available;
    }

    public function post(GoodsReceipt $receipt,array $data,int $actorId):PurchaseReturnRecord
    {
        return DB::transaction(function()use($receipt,$data,$actorId){
            $locked=GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='posted')
                throw ValidationException::withMessages(['goods_receipt_id'=>'Only posted GRNs can be returned.']);
            if($data['returned_on']<$locked->received_on->toDateString())
                throw ValidationException::withMessages(['returned_on'=>'Return date cannot precede receipt date.']);

            $duplicate=PurchaseReturnRecord::where('submission_token',$data['submission_token'])->first();
            if($duplicate){
                if($duplicate->goods_receipt_id===$locked->id &&
                    $duplicate->returned_on->toDateString()===$data['returned_on'] &&
                    $duplicate->reason===$data['reason']){
                    $submitted=[];
                    foreach($data['quantities'] as $lineId=>$q){
                        if($q!==null && $q!=='' && BigDecimal::of((string)$q)->isPositive())
                            $submitted[(int)$lineId]=BigDecimal::of((string)$q);
                    }
                    $existing=$duplicate->lines->keyBy('goods_receipt_line_id');
                    if(count($submitted)===$existing->count() &&
                        collect($submitted)->every(fn($quantity,$lineId)=>
                            $existing->has($lineId) && $quantity->isEqualTo((string)$existing[$lineId]->quantity)))
                        return $duplicate;
                }
                throw ValidationException::withMessages(['submission_token'=>'This return token belongs to a different transaction.']);
            }

            $order=$locked->purchaseOrder()->with('currency')->firstOrFail();
            $rows=$locked->lines()->lockForUpdate()->get()->keyBy('id');
            $remaining=$this->remaining($locked);
            $requested=[];
            foreach($data['quantities'] as $lineId=>$raw){
                if($raw===null||$raw==='')continue;
                if(!ctype_digit((string)$lineId) || !$rows->has((int)$lineId))
                    throw ValidationException::withMessages(['quantities'=>'Return includes a line outside the selected GRN.']);
                $amount=BigDecimal::of((string)$raw);
                if(!$amount->isPositive())continue;
                if($amount->isGreaterThan((string)($remaining[$lineId]??'0')))
                    throw ValidationException::withMessages(['quantities'=>'Return exceeds the remaining received quantity for a GRN line.']);
                $requested[(int)$lineId]=$amount->toScale(6);
            }
            if(!$requested)
                throw ValidationException::withMessages(['quantities'=>'Enter at least one positive return quantity.']);

            $warehouseId=$data['company_warehouse_id']??$locked->company_warehouse_id;
            if($warehouseId){
                $warehouse=CompanyWarehouse::whereKey($warehouseId)->where('is_active',true)->first();
                if(!$warehouse)throw ValidationException::withMessages(['company_warehouse_id'=>'Choose an active source warehouse.']);
            }

            $return=PurchaseReturnRecord::create([
                'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
                'goods_receipt_id'=>$locked->id,'purchase_order_id'=>$order->id,
                'currency_id'=>$order->currency_id,'company_warehouse_id'=>$warehouseId,
                'returned_on'=>$data['returned_on'],
                'supplier_reference'=>$data['supplier_reference']??null,
                'status'=>'posted_pending_credit','financial_status'=>'supplier_credit_pending',
                'reason'=>$data['reason'],'created_by'=>$actorId,
            ]);

            foreach($requested as $lineId=>$quantity){
                $source=$rows[(int)$lineId];
                $unitPrice=$source->unit_price_snapshot!==null ? (string)$source->unit_price_snapshot : null;
                $value=$unitPrice===null?null:(string)BigDecimal::of($unitPrice)->multipliedBy($quantity)->toScale(10);
                $line=$return->lines()->create([
                    'goods_receipt_line_id'=>$source->id,
                    'inventory_material_id'=>$source->inventory_material_id,
                    'quantity'=>(string)$quantity,
                    'unit_price_snapshot'=>$unitPrice,'supplier_credit_value_snapshot'=>$value,
                ]);
                $material=InventoryMaterial::findOrFail($source->inventory_material_id);
                $this->stock->post($material,[
                    'type'=>'adjustment_out','direction'=>'out','quantity'=>(string)$quantity,
                    'company_warehouse_id'=>$warehouseId,
                    'reference_type'=>'purchase_return_line','reference_id'=>$line->id,
                    'occurred_on'=>$data['returned_on'],
                    'notes'=>'Supplier goods return '.$return->id.' from '.$locked->number,
                    'created_by'=>$actorId,
                ]);
            }
            // Do not claim a supplier credit or mutate an unreconciled payable.
            $return->forceFill(['number'=>'PRN-'.str_pad((string)$return->id,7,'0',STR_PAD_LEFT)])->saveQuietly();
            ActivityLog::record('purchases.goods.returned','purchase_return_record',$return->id,
                'Supplier return posted to physical inventory. Supplier credit not posted.',
                ['grn'=>$locked->number,'currency_id'=>$order->currency_id]);
            return $return;
        });
    }
}
