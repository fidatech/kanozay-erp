<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InventoryMaterial;
use App\Models\ProcurementRequest;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcurementRequestService
{
    public function submit(array $data,int $actorId):ProcurementRequest
    {
        return DB::transaction(function()use($data,$actorId){
            $existing=ProcurementRequest::where('submission_token',$data['submission_token'])->first();
            if($existing)throw ValidationException::withMessages(['submission_token'=>'This request was already submitted.']);
            $seen=[];
            $materials=InventoryMaterial::whereIn('id',array_column($data['lines'],'inventory_material_id'))
                ->where('is_active',true)->get()->keyBy('id');
            $lines=[];
            foreach($data['lines'] as $line){
                $id=(int)$line['inventory_material_id'];
                if(isset($seen[$id]) || !$materials->has($id))
                    throw ValidationException::withMessages(['lines'=>'Choose each active material once per request.']);
                $seen[$id]=true;
                $qty=BigDecimal::of((string)$line['quantity']);
                if(!$qty->isPositive() || $qty->isGreaterThan(BigDecimal::of('99999999999999.999999')))
                    throw ValidationException::withMessages(['lines'=>'Invalid requested material quantity.']);
                $lines[]=[
                    'inventory_material_id'=>$id,'quantity'=>(string)$qty->toScale(6),
                    'unit_snapshot'=>$materials[$id]->unit,'description'=>$line['description']??null,
                ];
            }
            if(!$lines)throw ValidationException::withMessages(['lines'=>'Choose at least one material.']);
            $req=ProcurementRequest::create([
                'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
                'status'=>'submitted','requested_on'=>$data['requested_on'],
                'required_on'=>$data['required_on']??null,
                'notes'=>$data['notes']??null,'created_by'=>$actorId,
            ]);
            $req->lines()->createMany($lines);
            $req->update(['number'=>'REQ-'.str_pad((string)$req->id,7,'0',STR_PAD_LEFT)]);
            ActivityLog::record('procurement.request.submitted','procurement_request',$req->id,
                'Material requisition '.$req->number.' submitted for review.');
            return $req;
        });
    }

    public function decide(ProcurementRequest $request,string $decision,?string $note,int $actorId):ProcurementRequest
    {
        return DB::transaction(function()use($request,$decision,$note,$actorId){
            $locked=ProcurementRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='submitted')
                throw ValidationException::withMessages(['decision'=>'Only submitted requests can be reviewed.']);
            if($locked->created_by===$actorId)
                throw ValidationException::withMessages(['decision'=>'The requester cannot approve or reject their own request.']);
            $locked->update([
                'status'=>$decision,'decision_note'=>$note,
                'approved_by'=>$actorId,'decided_at'=>now(),
            ]);
            ActivityLog::record('procurement.request.'.$decision,'procurement_request',$locked->id,
                'Requisition '.$locked->number.' '.$decision);
            return $locked;
        });
    }

    public function convert(ProcurementRequest $request,array $data,int $actorId):PurchaseOrder
    {
        return DB::transaction(function()use($request,$data,$actorId){
            $locked=ProcurementRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='approved' || $locked->purchase_order_id)
                throw ValidationException::withMessages(['status'=>'Only approved, unconverted requests can become purchase orders.']);
            $lines=$locked->lines()->orderBy('id')->lockForUpdate()->get();
            if($lines->isEmpty())throw ValidationException::withMessages(['lines'=>'Requisition has no material lines.']);
            foreach($lines as $line){
                $id=(string)$line->id;
                if(!array_key_exists($id,$data['unit_prices']))
                    throw ValidationException::withMessages(['unit_prices'=>'A supplier unit price is required for every line.']);
                if(!InventoryMaterial::whereKey($line->inventory_material_id)->where('is_active',true)->exists())
                    throw ValidationException::withMessages(['lines'=>'The requisition contains an inactive material.']);
            }
            $order=PurchaseOrder::create([
                'number'=>'TMP-'.Str::uuid(),'supplier_id'=>$data['supplier_id'],
                'currency_id'=>$data['currency_id'],'source'=>$data['source'],
                'order_date'=>$data['order_date'],'expected_date'=>$data['expected_date']??null,
                'notes'=>'From approved requisition '.$locked->number.($data['notes']??''?"\n".$data['notes']:''),
                'created_by'=>$actorId,'status'=>'draft',
            ]);
            $order->update(['number'=>'PO-'.str_pad((string)$order->id,6,'0',STR_PAD_LEFT)]);
            foreach($lines as $line){
                $price=(string)BigDecimal::of((string)$data['unit_prices'][$line->id])->toScale(4);
                $quantity=BigDecimal::of((string)$line->quantity);
                // Purchase-order native quantities have four decimal places.
                if(!$quantity->isEqualTo($quantity->toScale(4,\Brick\Math\RoundingMode::Down)))
                    throw ValidationException::withMessages(['lines'=>'Purchase orders support four decimal places; review requested quantity before conversion.']);
                $order->items()->create([
                    'description'=>$line->description?:$line->material->name,
                    'inventory_material_id'=>$line->inventory_material_id,
                    'quantity'=>(string)$quantity->toScale(4),
                    'unit_price'=>$price,
                ]);
            }
            $locked->update(['status'=>'converted','purchase_order_id'=>$order->id]);
            ActivityLog::record('procurement.request.converted','procurement_request',$locked->id,
                'Approved requisition converted to draft PO '.$order->number);
            return $order;
        });
    }
}
