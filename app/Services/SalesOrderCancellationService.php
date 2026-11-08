<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderCancellationService
{
    /**
     * Confirmed sales orders are normally immutable. This controlled,
     * audited transition allows cancellation only before any sales invoice
     * line references an accepted-order line.
     */
    public function cancel(SalesOrder $order,array $data,int $actorId):void
    {
        DB::transaction(function()use($order,$data,$actorId){
            $locked=SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing=DB::table('sales_order_cancellations')
                ->where('submission_token',$data['submission_token'])->first();
            if($existing){
                if((int)$existing->sales_order_id===(int)$locked->id &&
                    $existing->reason===trim($data['reason']))return;
                throw ValidationException::withMessages([
                    'submission_token'=>'This cancellation token belongs to a different order or reason.',
                ]);
            }
            if($locked->status!=='confirmed'){
                throw ValidationException::withMessages([
                    'sales_order'=>'Only confirmed, unbilled orders can be cancelled.',
                ]);
            }
            if(DB::table('sales_invoice_lines as invoiced')
                ->join('sales_order_lines as original',
                    'original.id','=','invoiced.sales_order_line_id')
                ->where('original.sales_order_id',$locked->id)->exists()){
                throw ValidationException::withMessages([
                    'sales_order'=>'This order already has posted or linked invoice lines. Use a reviewed credit or adjustment workflow instead of cancelling the accepted order.',
                ]);
            }
            // Query-builder update is the deliberate controlled exception to
            // the immutable SalesOrder Eloquent model update guard.
            DB::table('sales_orders')->where('id',$locked->id)->update([
                'status'=>'cancelled','updated_at'=>now(),
            ]);
            DB::table('sales_order_cancellations')->insert([
                'sales_order_id'=>$locked->id,
                'submission_token'=>$data['submission_token'],
                'reason'=>trim($data['reason']),
                'cancelled_by'=>$actorId,'cancelled_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('sales_order.cancelled','sales_order',$locked->id,
                'Uninvoiced customer order cancelled after written authorization.',
                ['reason'=>$data['reason'],'cancelled_by'=>$actorId]);
        });
    }
}
