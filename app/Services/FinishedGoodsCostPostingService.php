<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\ProductionBatch;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturnQuarantine;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One original-cost-currency COGS journal per invoice line, and one asset
 * recovery journal per independently QC-released customer return. Selling
 * currency is NEVER used to value a factory batch without an approved FX
 * conversion process.
 */
class FinishedGoodsCostPostingService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly ManufacturingLedgerPolicyService $policies,
    ) {}

    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['cost_of_goods_sold'=>$message]);
    }

    private function journal(int $currencyId,string $date,string $reference,string $description,
        int $debitLedger,int $creditLedger,string $amount,int $actorId):JournalEntry
    {
        DocumentSequence::firstOrCreate(['document_type'=>'finished_goods_cost_journal'],[
            'prefix'=>'FGC-','next_number'=>1,'padding'=>7,'is_active'=>true,
        ]);
        $entry=JournalEntry::create([
            'number'=>DocumentSequence::issue('finished_goods_cost_journal'),
            'entry_date'=>$date,'reference'=>substr($reference,0,100),
            'description'=>$description,'currency_id'=>$currencyId,
            'status'=>'draft','created_by'=>$actorId,
        ]);
        $entry->lines()->createMany([
            ['ledger_account_id'=>$debitLedger,'debit'=>$amount,'credit'=>'0'],
            ['ledger_account_id'=>$creditLedger,'debit'=>'0','credit'=>$amount],
        ]);
        $this->journals->post($entry,$actorId);
        return $entry;
    }

    public function assertCapitalized(int $batchId,string $saleDate):object
    {
        $posted=DB::table('production_batch_cost_postings')
            ->where('production_batch_id',$batchId)->first();
        if(!$posted){
            $this->reject('Finished batch #'.$batchId.
                ' has not been financially capitalized in an approved original source currency. Finance must reconcile and post inventory cost before new invoice dispatch.');
        }
        if($saleDate<$posted->accounting_date){
            $this->reject('Sales dispatch cannot predate the posted original-currency manufacturing capitalization.');
        }
        $policy=$this->policies->approved((int)$posted->currency_id);
        if((int)$policy->id!==(int)$posted->manufacturing_cost_ledger_policy_id){
            $this->reject('Cost ledger policy was changed after production capitalization. Finance must reconcile ledger mapping.');
        }
        foreach([$posted->issue_journal_id,$posted->capitalization_journal_id] as $id){
            $journal=DB::table('journal_entries')->where('id',$id)->first();
            if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
                DB::table('journal_entries')->where('reversal_of_id',$id)->exists()){
                $this->reject('One of the original manufacturing cost journals is unposted or reversed.');
            }
        }
        return $posted;
    }

    public function postInvoiceLine(SalesInvoiceLine $line,int $actorId):object
    {
        return DB::transaction(function()use($line,$actorId){
            $batch=ProductionBatch::whereKey($line->production_batch_id)
                ->lockForUpdate()->firstOrFail();
            $original=SalesInvoiceLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $invoice=DB::table('sales_invoices')->where('id',$original->sales_invoice_id)->first();
            if(!$invoice)$this->reject('Sales invoice document not found.');
            $existing=DB::table('production_invoice_cogs_postings')
                ->where('sales_invoice_line_id',$original->id)->first();
            if($existing)return $existing;
            $posted=$this->assertCapitalized($batch->id,$invoice->invoice_date);
            $policy=$this->policies->approved((int)$posted->currency_id);
            $quantity=(int)$original->quantity_packages;
            if($quantity<1)$this->reject('A sales line requires a positive dispatched package quantity.');
            if((int)$original->production_batch_id!==(int)$posted->production_batch_id)
                $this->reject('Sales invoice line and production cost batch mismatch.');

            $priorSales=DB::table('production_invoice_cogs_postings')
                ->where('production_batch_cost_posting_id',$posted->id)->get();
            $priorQty=0;$netCost=BigDecimal::zero();
            foreach($priorSales as $row){
                $priorQty+=(int)$row->quantity_packages;
                $netCost=$netCost->plus((string)$row->amount);
            }
            $returns=DB::table('production_return_cost_postings as recovery')
                ->join('production_invoice_cogs_postings as sold',
                    'sold.id','=','recovery.production_invoice_cogs_posting_id')
                ->where('sold.production_batch_cost_posting_id',$posted->id)
                ->get(['recovery.quantity_packages','recovery.amount']);
            foreach($returns as $row){
                $priorQty-=(int)$row->quantity_packages;
                $netCost=$netCost->minus((string)$row->amount);
            }
            if($priorQty<0 || $priorQty+$quantity>(int)$posted->accepted_packages){
                $this->reject('Net costed dispatched units exceed the QC-accepted batch quantity. Reconcile historic COGS and physical returns.');
            }
            $total=BigDecimal::of((string)$posted->total_amount);
            if($priorQty+$quantity===(int)$posted->accepted_packages){
                $amount=$total->minus($netCost);
            }else{
                $amount=$total->multipliedBy((string)$quantity)
                    ->dividedBy((string)$posted->accepted_packages,4,RoundingMode::HalfUp);
            }
            if(!$amount->isPositive() || $amount->isGreaterThan('9999999999999999.9999') ||
                $amount->isGreaterThan($total->minus($netCost))){
                $this->reject('The calculated COGS is outside the remaining original-currency batch inventory value.');
            }
            $amount=(string)$amount->toScale(4,RoundingMode::HalfUp);
            $journal=$this->journal((int)$posted->currency_id,$invoice->invoice_date,
                $invoice->number,
                'Original-currency COGS for '.$invoice->number.' / '.$batch->batch_number,
                (int)$policy->cogs_ledger_id,
                (int)$policy->finished_inventory_ledger_id,
                $amount,$actorId);
            $id=DB::table('production_invoice_cogs_postings')->insertGetId([
                'sales_invoice_line_id'=>$original->id,
                'production_batch_cost_posting_id'=>$posted->id,
                'quantity_packages'=>$quantity,'amount'=>$amount,
                'journal_entry_id'=>$journal->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('sales.invoice_line.cogs_posted','sales_invoice_line',$original->id,
                'Original batch inventory value recognized as COGS at physical sales invoice dispatch.',
                ['cost_currency_id'=>$posted->currency_id,'amount'=>$amount,
                 'quantity_packages'=>$quantity,'journal'=>$journal->number]);
            return DB::table('production_invoice_cogs_postings')->where('id',$id)->first();
        });
    }

    public function postReturnRecovery(SalesReturnQuarantine $quarantine,string $date,int $actorId):object
    {
        return DB::transaction(function()use($quarantine,$date,$actorId){
            $record=SalesReturnQuarantine::whereKey($quarantine->id)
                ->lockForUpdate()->firstOrFail();
            $previous=DB::table('production_return_cost_postings')
                ->where('sales_return_quarantine_id',$record->id)->first();
            if($previous)return $previous;
            if($record->status!=='quarantined'){
                $this->reject('Only a quarantined return awaiting independent QC release can restore inventory cost.');
            }
            $credit=DB::table('sales_credit_note_lines')
                ->where('id',$record->sales_credit_note_line_id)->first();
            if(!$credit)$this->reject('Original customer credit line is missing.');
            $source=DB::table('production_invoice_cogs_postings')
                ->where('sales_invoice_line_id',$credit->sales_invoice_line_id)->first();
            if(!$source){
                $this->reject('The original sales dispatch lacks a COGS journal. Reconcile historical invoice cost before authorizing saleable return recovery.');
            }
            $sale=DB::table('sales_invoice_lines as item')
                ->join('sales_invoices as invoice','invoice.id','=','item.sales_invoice_id')
                ->where('item.id',$credit->sales_invoice_line_id)
                ->select('item.quantity_packages','invoice.invoice_date')->first();
            if(!$sale || $date<$sale->invoice_date){
                $this->reject('Physical return cannot precede its original customer invoice date.');
            }
            $cost=DB::table('production_batch_cost_postings')
                ->where('id',$source->production_batch_cost_posting_id)->first();
            if(!$cost || (int)$cost->production_batch_id!==(int)$record->production_batch_id){
                $this->reject('Original sold production batch does not match the QC-return source.');
            }
            $policy=$this->policies->approved((int)$cost->currency_id);
            if((int)$policy->id!==(int)$cost->manufacturing_cost_ledger_policy_id){
                $this->reject('Batch cost policy has changed since the source sale.');
            }
            $sourceJournal=DB::table('journal_entries')->where('id',$source->journal_entry_id)->first();
            if(!$sourceJournal || $sourceJournal->status!=='posted' ||
                $date<$sourceJournal->entry_date ||
                DB::table('journal_entries')->where('reversal_of_id',$sourceJournal->id)->exists()){
                $this->reject('Original COGS journal must remain posted and not postdate the return recovery.');
            }
            $prior=DB::table('production_return_cost_postings')
                ->where('production_invoice_cogs_posting_id',$source->id)->get();
            $usedQty=0;$usedCost=BigDecimal::zero();
            foreach($prior as $row){
                $usedQty+=(int)$row->quantity_packages;
                $usedCost=$usedCost->plus((string)$row->amount);
            }
            $qty=(int)$record->quantity_packages;
            if($qty<1 || $usedQty+$qty>(int)$source->quantity_packages){
                $this->reject('Returned packages exceed the original sale quantity already charged to COGS.');
            }
            $originalCost=BigDecimal::of((string)$source->amount);
            $amount=($usedQty+$qty===(int)$source->quantity_packages)
                ?$originalCost->minus($usedCost)
                :$originalCost->multipliedBy((string)$qty)
                    ->dividedBy((string)$source->quantity_packages,4,RoundingMode::HalfUp);
            if(!$amount->isPositive() || $amount->isGreaterThan($originalCost->minus($usedCost))){
                $this->reject('Physical return recovery exceeds the remaining original customer COGS amount.');
            }
            $amount=(string)$amount->toScale(4,RoundingMode::HalfUp);
            $journal=$this->journal((int)$cost->currency_id,$date,
                'RET-QC-'.$record->id,
                'QC-approved inventory asset recovery from original customer COGS',
                (int)$policy->finished_inventory_ledger_id,
                (int)$policy->cogs_ledger_id,
                $amount,$actorId);
            $id=DB::table('production_return_cost_postings')->insertGetId([
                'sales_return_quarantine_id'=>$record->id,
                'production_invoice_cogs_posting_id'=>$source->id,
                'quantity_packages'=>$qty,'amount'=>$amount,
                'journal_entry_id'=>$journal->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('sales.return.recovered_cogs','sales_return_quarantine',$record->id,
                'Independent QC stock recovery reverses original invoice line COGS in factory source currency.',
                ['amount'=>$amount,'currency_id'=>$cost->currency_id,'journal'=>$journal->number]);
            return DB::table('production_return_cost_postings')->where('id',$id)->first();
        });
    }
}
