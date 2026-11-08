<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting assembled from posted source documents. Currency values
 * are never summed across currencies or presented as a profit, COGS or cash
 * measure. Credit notes are reported by their own posting dates, not
 * retroactively attributed to the invoice period.
 */
class OperationsAnalyticsService
{
    public function summarize(string $from,string $to):array
    {
        $currencyBuckets=[];
        $add=function(string $code,string $name,string $amount)use(&$currencyBuckets):void{
            if(!isset($currencyBuckets[$code])){
                $currencyBuckets[$code]=[
                    'currency'=>$code,
                    'invoices'=>0,'invoice_total'=>BigDecimal::zero(),
                    'credits'=>0,'credit_total'=>BigDecimal::zero(),
                ];
            }
            if($name==='invoice'){
                $currencyBuckets[$code]['invoices']++;
                $currencyBuckets[$code]['invoice_total']=$currencyBuckets[$code]['invoice_total']->plus($amount);
            }else{
                $currencyBuckets[$code]['credits']++;
                $currencyBuckets[$code]['credit_total']=$currencyBuckets[$code]['credit_total']->plus($amount);
            }
        };
        foreach(DB::table('sales_invoices')
            ->where('status','posted')
            ->whereBetween('invoice_date',[$from,$to])
            ->orderBy('id')->cursor(['currency_code','total']) as $invoice){
            $add((string)$invoice->currency_code,'invoice',(string)$invoice->total);
        }
        foreach(DB::table('sales_credit_notes')
            ->whereBetween('credit_date',[$from,$to])
            ->orderBy('id')->cursor(['currency_code','total']) as $credit){
            $add((string)$credit->currency_code,'credit',(string)$credit->total);
        }
        ksort($currencyBuckets);
        foreach($currencyBuckets as &$bucket){
            $bucket['invoice_total']=(string)$bucket['invoice_total']->toScale(4);
            $bucket['credit_total']=(string)$bucket['credit_total']->toScale(4);
        }
        unset($bucket);

        $released=DB::table('production_batches')
            ->whereNotNull('released_at')
            ->whereDate('released_at','>=',$from)
            ->whereDate('released_at','<=',$to)
            ->selectRaw('COUNT(*) AS batches, COALESCE(SUM(accepted_packages),0) AS accepted')
            ->first();
        $samples=DB::table('quality_samples')
            ->whereBetween('collected_on',[$from,$to])
            ->select('source_type','status')
            ->get()->groupBy('source_type');
        $sourceSamples=[];
        foreach(['incoming_material','finished_batch'] as $source){
            $rows=$samples->get($source,collect());
            $sourceSamples[$source]=[
                'collected'=>$rows->count(),
                'approved'=>$rows->where('status','approved')->count(),
                'rejected'=>$rows->where('status','rejected')->count(),
                'pending'=>$rows->whereIn('status',['registered','in_testing'])->count(),
            ];
        }

        $operational=[
            'purchase_orders'=>DB::table('purchase_orders')
                ->whereBetween('order_date',[$from,$to])->count(),
            'production_plans'=>DB::table('production_orders')
                ->whereDate('created_at','>=',$from)
                ->whereDate('created_at','<=',$to)->count(),
            'released_batches'=>(int)($released->batches??0),
            'accepted_packages'=>(int)($released->accepted??0),
            'posted_journals'=>DB::table('journal_entries')
                ->where('status','posted')->whereBetween('entry_date',[$from,$to])->count(),
            'delivery_confirmations'=>DB::table('sales_delivery_tracking_events')
                ->where('status','delivered')->whereDate('occurred_at','>=',$from)
                ->whereDate('occurred_at','<=',$to)->count(),
            'open_nonconformances'=>DB::table('quality_nonconformances')
                ->whereIn('status',['open','investigating'])->count(),
            'held_incoming_lots'=>DB::table('quality_material_lot_holds')
                ->where('status','held')->count(),
        ];
        return [
            'from'=>$from,'to'=>$to,'currencies'=>array_values($currencyBuckets),
            'operations'=>$operational,'samples'=>$sourceSamples,
        ];
    }
}
