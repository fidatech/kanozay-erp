<?php

namespace App\Services;

use App\Models\FinishedGoodDispatchLine;
use App\Models\ProductionBatch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Source-backed DISPATCH cost exposure only. It never posts cost of goods
 * sold or removes stock value. Individual batch costs can be indicative
 * while source expense, WIP and finished-goods GL are not capitalized.
 */
class DispatchCostReconciliationService
{
    public function rows(Collection $lines):Collection
    {
        if($lines->isEmpty())return collect();
        $batchIds=$lines->pluck('production_batch_id')->unique()->values();
        $batches=ProductionBatch::query()
            ->with(['costs','finishedGood'])
            ->whereIn('id',$batchIds)->get()->keyBy('id');
        $overheads=DB::table('production_overhead_allocations as overhead')
            ->join('currencies as currency','currency.id','=','overhead.currency_id')
            ->join('journal_lines as source','source.id','=','overhead.source_journal_line_id')
            ->join('journal_entries as journal','journal.id','=','source.journal_entry_id')
            ->whereIn('overhead.production_batch_id',$batchIds)
            ->get(['overhead.production_batch_id','overhead.status',
                'overhead.amount','overhead.reviewed_at',
                'journal.entry_date as expense_date',
                'journal.id as source_journal_entry_id',
                'journal.status as expense_status',
                'journal.reversal_of_id as expense_reversal_id',
                'currency.code as currency_code'])
            ->groupBy('production_batch_id');
        $results=collect();
        foreach($lines as $line){
            $batch=$batches->get($line->production_batch_id);
            $reason=[];
            $amount=null;$unitCost=null;$currency=null;
            if(!$batch || (int)$batch->accepted_packages<=0 ||
                $batch->status!=='released'){
                $reason[]='No released QC-approved batch with accepted quantities';
            }
            $costs=$batch?->costs??collect();
            if($costs->isEmpty() ||
                $costs->contains(fn($c)=>$c->material_cost===null ||
                    BigDecimal::of((string)$c->unvalued_quantity)->isPositive())){
                $reason[]='Missing or unvalued original FIFO material cost';
            }
            $approved=$overheads->get($line->production_batch_id,collect());
            if($approved->where('status','submitted')->isNotEmpty()){
                $reason[]='Unapproved overhead allocation remains';
            }
            foreach($approved->where('status','approved') as $item){
                if(!$item->reviewed_at ||
                    substr((string)$item->reviewed_at,0,10)>$line->dispatch->dispatch_date->toDateString()){
                    $reason[]='Overhead approved after dispatch or missing approval time';
                    break;
                }
                if($item->expense_date>$line->dispatch->dispatch_date->toDateString() ||
                    $item->expense_status!=='posted' || $item->expense_reversal_id ||
                    DB::table('journal_entries')
                        ->where('reversal_of_id',$item->source_journal_entry_id??0)->exists()){
                    $reason[]='Source overhead expense postdates dispatch or has invalid journal evidence';
                    break;
                }
            }
            $currencyCodes=$costs->pluck('currency_code')->filter()
                ->merge($approved->where('status','approved')->pluck('currency_code'))
                ->unique()->values();
            if($currencyCodes->count()!==1){
                $reason[]='Multiple or unknown source currencies';
            }else{
                $currency=(string)$currencyCodes->first();
            }
            if(!$reason){
                $total=BigDecimal::zero();
                foreach($costs as $cost){
                    $total=$total->plus((string)$cost->material_cost);
                }
                foreach($approved->where('status','approved') as $row){
                    $total=$total->plus((string)$row->amount);
                }
                $unitCost=$total->dividedBy(
                    (string)$batch->accepted_packages,10,RoundingMode::HalfUp);
                $amount=(string)$total->multipliedBy((string)$line->quantity_packages)
                    ->dividedBy((string)$batch->accepted_packages,4,RoundingMode::HalfUp);
            }
            $results->push([
                'line_id'=>$line->id,
                'dispatch_id'=>$line->finished_good_dispatch_id,
                'batch_id'=>$line->production_batch_id,
                'batch'=>$batch?->batch_number??'Unknown batch',
                'product'=>$batch?->finishedGood?->name??'Unknown product',
                'date'=>$line->dispatch?->dispatch_date?->toDateString(),
                'reference'=>$line->dispatch?->number,
                'packages'=>(int)$line->quantity_packages,
                'currency'=>$currency,
                'indicative_cost'=>$amount,
                'unit_cost'=>$unitCost?(string)$unitCost:null,
                'status'=>$reason?'blocked':'source_estimate_only',
                'reasons'=>$reason,
            ]);
        }
        return $results;
    }

    public function summary(Collection $rows):array
    {
        $byCurrency=[];
        $blocked=0;
        foreach($rows as $row){
            if($row['status']!=='source_estimate_only'){$blocked++;continue;}
            $code=$row['currency'];
            if(!isset($byCurrency[$code])){
                $byCurrency[$code]=['currency'=>$code,'packages'=>0,
                    'indicative_cost'=>BigDecimal::zero()];
            }
            $byCurrency[$code]['packages']+=$row['packages'];
            $byCurrency[$code]['indicative_cost']=$byCurrency[$code]['indicative_cost']
                ->plus($row['indicative_cost']);
        }
        ksort($byCurrency);
        foreach($byCurrency as &$item){
            $item['indicative_cost']=(string)$item['indicative_cost']->toScale(4);
        }
        unset($item);
        return [
            'blocked_lines'=>$blocked,
            'costed_lines'=>$rows->count()-$blocked,
            'by_currency'=>array_values($byCurrency),
        ];
    }
}
