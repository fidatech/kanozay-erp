<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One original manufacturing COGS allocation for both SALEABLE QC releases
 * and independently SCRAPPED customer returns of the same genuine sale line.
 *
 * The existing two source verifiers independently authenticate the original
 * invoice/credit, QC outcome, physical restock (only released), signed scrap
 * (only scrapped), date, original source COGS and independent actors. This
 * service then shares ONE 4-decimal invoice-level cost budget across BOTH
 * outcome types, before financial proposal/review/GL execution.
 *
 * Non-mixed invoice lines retain their existing signed allocation and source
 * fingerprint: previously proposed historical batches are not rewritten.
 */
final class HistoricalBatchMixedQcAllocationService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_batch_qc_allocation'=>$message]);
    }

    /**
     * @param array<int|string,string> $invoiceCosts Original invoice-line COGS
     * @param array<int,array<string,mixed>> $released
     * @param array<int,array<string,mixed>> $scrapped
     * @return array{returns:array,scraps:array}
     */
    public function allocate(Collection $dispatches,array $invoiceCosts,
        array $released,array $scrapped): array
    {
        $grouped=[];
        foreach($released as $index=>$line){
            $id=(int)$line['sales_invoice_line_id'];
            $grouped[$id][]=[
                'type'=>'return','index'=>$index,
                'date'=>$line['date'],'qc_id'=>$line['qc_id'],
                'quantity'=>$line['quantity'],
                'source_fingerprint'=>$line['fingerprint'],
                'original_cost'=>$line['original_sale_cost'],
                'credit_id'=>$line['credit_line_id'],
            ];
        }
        foreach($scrapped as $index=>$line){
            $id=(int)$line['sales_invoice_line_id'];
            $grouped[$id][]=[
                'type'=>'scrap','index'=>$index,
                'date'=>$line['date'],'qc_id'=>$line['qc_id'],
                'quantity'=>$line['quantity'],
                'source_fingerprint'=>$line['fingerprint'],
                'original_cost'=>$line['original_sale_cost'],
                'credit_id'=>$line['credit_line_id'],
            ];
        }

        foreach($grouped as $invoiceLineId=>$events){
            $releasedCount=count(array_filter($events,
                fn($event)=>$event['type']==='return'));
            $scrappedCount=count($events)-$releasedCount;
            if(!$releasedCount || !$scrappedCount)
                continue; // Preserve earlier approved single-outcome proposals.

            $physical=$dispatches->first(fn($source)=>
                (int)($source->invoice_line_id??0)===(int)$invoiceLineId);
            $sourceCost=$invoiceCosts[$invoiceLineId]??null;
            if(!$physical || $sourceCost===null ||
                (int)$physical->quantity_packages<1)
                $this->fail('A mixed QC return/scrap plan requires one authentic original invoice line and a source-priced physical dispatch.');
            $soldQty=(int)$physical->quantity_packages;
            $total=BigDecimal::of((string)$sourceCost)->toScale(4);
            if(!$total->isPositive())
                $this->fail('Mixed customer QC allocation requires a positive original-currency sale COGS amount.');

            $seenQc=[];$seenCredit=[];$sumQty=0;
            foreach($events as $event){
                if((int)$event['quantity']<1 ||
                    !BigDecimal::of((string)$event['original_cost'])->isEqualTo($total))
                    $this->fail('Every released/scrapped portion must reference the same unchanged original invoice COGS and a positive source quantity.');
                if(isset($seenQc[$event['qc_id']]) || isset($seenCredit[$event['credit_id']]))
                    $this->fail('A single customer credit or QC source cannot be both saleably restored and scrapped.');
                $seenQc[$event['qc_id']]=true;
                $seenCredit[$event['credit_id']]=true;
                $sumQty+=(int)$event['quantity'];
            }
            if($sumQty>$soldQty)
                $this->fail('Combined saleable-return and scrapped packages exceed the original physical invoice-line quantity.');

            // Same-day source order: the genuine physical QC release is
            // cost-restored first, then a genuine independent QC scrap cost
            // reclassification. This is the executor's identical order.
            usort($events,fn($a,$b)=>[
                $a['date'],$a['type']==='return'?0:1,$a['qc_id'],
            ]<=>[
                $b['date'],$b['type']==='return'?0:1,$b['qc_id'],
            ]);
            $jointSource=array_map(fn($event)=>[
                $event['type'],$event['qc_id'],$event['credit_id'],
                $event['date'],$event['quantity'],$event['source_fingerprint'],
            ],$events);

            $usedQty=0;$usedCost=BigDecimal::zero();
            foreach($events as $position=>$event){
                $qty=(int)$event['quantity'];
                $usedQty+=$qty;
                $amount=$usedQty===$soldQty
                    ?$total->minus($usedCost)
                    :$total->multipliedBy((string)$qty)
                        ->dividedBy((string)$soldQty,4,RoundingMode::HalfUp);
                if(!$amount->isPositive() ||
                    $amount->isGreaterThan($total->minus($usedCost)))
                    $this->fail('Combined released/scrapped QC allocation exceeds the source manufacturing COGS budget after 4-decimal rounding.');
                $fixed=(string)$amount->toScale(4);
                $sharedFingerprint=hash('sha256',json_encode([
                    'source_invoice_line_id'=>$invoiceLineId,
                    'source_sale_cogs'=>(string)$total,
                    'source_sold_packages'=>$soldQty,
                    'all_signed_original_qc_events'=>$jointSource,
                    'event_position'=>$position,
                    'prior_allocated_packages'=>$usedQty-$qty,
                    'prior_allocated_source_cost'=>(string)$usedCost,
                    'source_outcome_fingerprint'=>$event['source_fingerprint'],
                    'verified_original_cost_fraction'=>$fixed,
                ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
                if($event['type']==='return'){
                    $released[$event['index']]['expected_cost']=$fixed;
                    $released[$event['index']]['fingerprint']=$sharedFingerprint;
                }else{
                    $scrapped[$event['index']]['amount']=$fixed;
                    $scrapped[$event['index']]['fingerprint']=$sharedFingerprint;
                }
                $usedCost=$usedCost->plus($amount);
            }
        }

        return ['returns'=>$released,'scraps'=>$scrapped];
    }
}
