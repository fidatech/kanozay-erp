<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\GoodsReceiptLandedCost;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Each original-currency GRN landed cost is recognized once, by transferring
 * one exact, previously posted expense journal debit to raw inventory.
 *
 * No FX conversion, guessed supplier payable, cost estimate or second physical
 * stock receipt is created. Existing lots remain unissuable until posted cost
 * evidence is reconciled.
 */
class GoodsReceiptLandedCostPostingService
{
    public function __construct(
        private readonly ManufacturingLedgerPolicyService $policies,
        private readonly JournalPostingService $journals,
    ) {}

    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['landed_cost_posting'=>$message]);
    }

    public function post(int $costId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($costId,$data,$actorId){
            app(FinancePeriodClosingService::class)->holdPostingGate();
            $cost=GoodsReceiptLandedCost::whereKey($costId)
                ->lockForUpdate()->firstOrFail();
            $existing=DB::table('goods_receipt_landed_cost_postings')
                ->where('goods_receipt_landed_cost_id',$costId)->first();
            if($existing){
                if((int)$existing->source_expense_journal_line_id===(int)$data['source_expense_journal_line_id']
                    && $existing->accounting_date===$data['accounting_date']
                    && $existing->approval_reference===trim($data['approval_reference'])){
                    return $existing;
                }
                $this->reject('This landed-cost component is already capitalized. Do not post the same source cost twice.');
            }
            if((int)$cost->created_by===$actorId){
                $this->reject('The person who recorded the landed-cost allocation cannot approve its GL capitalization.');
            }
            $receipt=DB::table('goods_receipts')->where('id',$cost->goods_receipt_id)
                ->lockForUpdate()->first();
            if(!$receipt || $receipt->status!=='posted' ||
                !$receipt->cost_currency_id || !$receipt->cost_currency_code ||
                $receipt->cost_currency_code!==$cost->currency_code){
                $this->reject('Landed cost does not match a posted original-currency goods receipt.');
            }
            if($receipt->received_on>$data['accounting_date']){
                $this->reject('Landed cost GL capitalization cannot predate the original goods receipt.');
            }
            $policy=$this->policies->approved((int)$receipt->cost_currency_id);
            $inventory=(int)$policy->material_inventory_ledger_id;
            $amount=BigDecimal::of((string)$cost->amount);
            if(!$amount->isPositive() || $amount->isGreaterThan('9999999999999999.9999')){
                $this->reject('Original landed-cost amount exceeds the supported inventory GL journal value.');
            }
            $lines=DB::table('goods_receipt_landed_cost_allocations')
                ->where('goods_receipt_landed_cost_id',$cost->id)->get();
            if($lines->isEmpty()){
                $this->reject('This landed cost has no recorded individual receipt-line allocations.');
            }
            $allocated=BigDecimal::zero();
            $seen=[];
            foreach($lines as $allocation){
                if(isset($seen[$allocation->goods_receipt_line_id])){
                    $this->reject('Landed-cost allocation has duplicated source receipt lines.');
                }
                $seen[$allocation->goods_receipt_line_id]=true;
                $sourceLine=DB::table('goods_receipt_lines')
                    ->where('id',$allocation->goods_receipt_line_id)->first();
                if(!$sourceLine || (int)$sourceLine->goods_receipt_id!==(int)$receipt->id){
                    $this->reject('Allocated material line does not belong to the original goods receipt.');
                }
                $layer=DB::table('inventory_cost_layers')
                    ->where('source_type','goods_receipt_line')
                    ->where('source_id',$allocation->goods_receipt_line_id)
                    ->lockForUpdate()->first();
                if(!$layer || (int)$layer->currency_id!==(int)$receipt->cost_currency_id ||
                    (string)$layer->currency_code!==(string)$cost->currency_code){
                    $this->reject('The original receipt FIFO valuation layer is missing or has a different source currency.');
                }
                if(DB::table('inventory_cost_consumptions')
                    ->where('inventory_cost_layer_id',$layer->id)->exists()){
                    $this->reject('Material from this landed-cost allocation has already been consumed. Independently reconcile the historic asset/WIP journals before posting.');
                }
                $allocated=$allocated->plus((string)$allocation->amount);
            }
            if(!$allocated->isEqualTo($amount)){
                $this->reject('The detailed GRN landed-cost allocations do not add up to the immutable original component amount.');
            }
            $source=DB::table('journal_lines')
                ->where('id',$data['source_expense_journal_line_id'])
                ->lockForUpdate()->first();
            abort_unless($source,404);
            $expense=DB::table('ledger_accounts')
                ->where('id',$source->ledger_account_id)->first();
            if(!$expense || !$expense->is_active || $expense->is_group ||
                $expense->type!=='expense' || (int)$expense->id===(int)$policy->cogs_ledger_id ||
                DB::table('entity_ledger_accounts')
                    ->where('ledger_account_id',$expense->id)->exists()){
                $this->reject('Choose an active, independent posted freight/customs/handling expense debit, not COGS or a mapped trade ledger.');
            }
            if(!BigDecimal::of((string)$source->debit)->isEqualTo($amount) ||
                !BigDecimal::of((string)$source->credit)->isZero()){
                $this->reject('The source expense line debit must exactly match this single original-currency landed-cost component.');
            }
            if(DB::table('production_overhead_allocations')
                ->where('source_journal_line_id',$source->id)
                ->whereIn('status',['submitted','approved'])->exists() ||
                DB::table('goods_receipt_landed_cost_postings')
                ->where('source_expense_journal_line_id',$source->id)->exists()){
                $this->reject('The expense journal line is already allocated to manufacturing overhead or another landed-cost component.');
            }
            $sourceJournal=JournalEntry::whereKey($source->journal_entry_id)
                ->lockForUpdate()->firstOrFail();
            if($sourceJournal->status!=='posted' || $sourceJournal->reversal_of_id ||
                JournalEntry::where('reversal_of_id',$sourceJournal->id)->exists() ||
                (int)$sourceJournal->currency_id!==(int)$receipt->cost_currency_id ||
                $sourceJournal->entry_date->toDateString()>$data['accounting_date']){
                $this->reject('Source expense must be posted, unreversed and dated on or before the inventory capitalization in the original purchase currency.');
            }
            DocumentSequence::firstOrCreate(['document_type'=>'landed_cost_capitalization'],[
                'prefix'=>'LCC-','next_number'=>1,'padding'=>7,'is_active'=>true,
            ]);
            $number=DocumentSequence::issue('landed_cost_capitalization');
            $journal=JournalEntry::create([
                'number'=>$number,'entry_date'=>$data['accounting_date'],
                'reference'=>substr($receipt->number.' / '.$cost->component,0,100),
                'description'=>'Approved GRN '.$receipt->number.' landed cost '.$cost->component.
                    ' reclassified from posted expense to raw-material inventory',
                'currency_id'=>$receipt->cost_currency_id,
                'status'=>'draft','created_by'=>$actorId,
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$inventory,'debit'=>(string)$amount->toScale(4),
                    'credit'=>'0','memo'=>'Capitalized GRN source landed cost'],
                ['ledger_account_id'=>$expense->id,'debit'=>'0',
                    'credit'=>(string)$amount->toScale(4),
                    'memo'=>'Reclassify posted expense debit #'.$source->id],
            ]);
            $this->journals->post($journal,$actorId);
            $id=DB::table('goods_receipt_landed_cost_postings')->insertGetId([
                'goods_receipt_landed_cost_id'=>$cost->id,
                'source_expense_journal_line_id'=>$source->id,
                'inventory_ledger_id'=>$inventory,
                'expense_ledger_id'=>$expense->id,
                'currency_id'=>$receipt->cost_currency_id,
                'amount'=>(string)$amount->toScale(4),
                'accounting_date'=>$data['accounting_date'],
                'journal_entry_id'=>$journal->id,
                'approval_reference'=>trim($data['approval_reference']),
                'evidence_notes'=>trim($data['evidence_notes']),
                'approved_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('purchase.landed_cost.capitalized',
                'goods_receipt_landed_cost',$cost->id,
                'Original freight/customs/handling expense reclassified to source-currency FIFO inventory after independent finance review.',[
                    'gl_posting_id'=>$id,'journal_number'=>$journal->number,
                    'source_expense_line_id'=>$source->id,'currency_id'=>$receipt->cost_currency_id,
                    'amount'=>(string)$amount->toScale(4),
                ]);
            return DB::table('goods_receipt_landed_cost_postings')->where('id',$id)->first();
        });
    }
}
