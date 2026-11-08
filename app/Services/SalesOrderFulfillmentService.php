<?php

namespace App\Services;

use App\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only commercial progress for accepted sales orders. Invoicing,
 * physical dispatch, delivery documentation and credits are separate
 * records: a return does not automatically reopen an accepted order.
 */
class SalesOrderFulfillmentService
{
    public function summarize(Collection $orders): array
    {
        if($orders->isEmpty())return [];
        $ids=$orders->pluck('id')->all();
        $lines=DB::table('sales_order_lines')->whereIn('sales_order_id',$ids)
            ->orderBy('id')->get(['id','sales_order_id','description','quantity','unit','unit_price']);
        $lineIds=$lines->pluck('id')->all();
        $invoiced=DB::table('sales_invoice_lines as line')
            ->join('sales_invoices as invoice','invoice.id','=','line.sales_invoice_id')
            ->where('invoice.status','posted')->whereIn('line.sales_order_line_id',$lineIds)
            ->select('line.sales_order_line_id')
            ->selectRaw('SUM(line.quantity_packages) as packages')
            ->groupBy('line.sales_order_line_id')->pluck('packages','sales_order_line_id');
        $returned=DB::table('sales_credit_note_lines as returned')
            ->join('sales_invoice_lines as line','line.id','=','returned.sales_invoice_line_id')
            ->whereIn('line.sales_order_line_id',$lineIds)
            ->select('line.sales_order_line_id')
            ->selectRaw('SUM(returned.quantity_packages) as packages')
            ->groupBy('line.sales_order_line_id')->pluck('packages','sales_order_line_id');
        $delivery=DB::table('sales_delivery_note_lines as detail')
            ->join('sales_delivery_notes as note','note.id','=','detail.sales_delivery_note_id')
            ->join('sales_invoice_lines as line','line.id','=','detail.sales_invoice_line_id')
            ->whereIn('line.sales_order_line_id',$lineIds)
            ->where('note.status','!=','cancelled')
            ->select('line.sales_order_line_id')
            ->selectRaw('SUM(detail.quantity_packages) as documented')
            ->selectRaw("SUM(CASE WHEN note.status='delivered' THEN detail.quantity_packages ELSE 0 END) as received")
            ->groupBy('line.sales_order_line_id')->get()->keyBy('sales_order_line_id');

        $result=[];
        foreach($orders as $order){
            $result[$order->id]=[
                'ordered'=>0,'invoiced'=>0,'returned'=>0,
                'documented'=>0,'delivered'=>0,'remaining_to_invoice'=>0,
                'lines'=>[],
            ];
        }
        foreach($lines as $line){
            $ordered=(int)$line->quantity;
            $billed=(int)($invoiced[$line->id]??0);
            $credited=(int)($returned[$line->id]??0);
            $documented=(int)($delivery->get($line->id)?->documented??0);
            $delivered=(int)($delivery->get($line->id)?->received??0);
            $remaining=max(0,$ordered-$billed);
            $snapshot=&$result[$line->sales_order_id];
            $snapshot['ordered']+=$ordered;
            $snapshot['invoiced']+=$billed;
            $snapshot['returned']+=$credited;
            $snapshot['documented']+=$documented;
            $snapshot['delivered']+=$delivered;
            $snapshot['remaining_to_invoice']+=$remaining;
            $snapshot['lines'][$line->id]=[
                'ordered'=>$ordered,'invoiced'=>$billed,
                'returned'=>$credited,'documented'=>$documented,
                'delivered'=>$delivered,'remaining_to_invoice'=>$remaining,
            ];
            unset($snapshot);
        }
        return $result;
    }
}
