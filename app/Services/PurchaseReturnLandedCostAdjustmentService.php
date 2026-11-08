<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\PurchaseReturnRecord;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalizes a requested supplier physical return involving separately
 * capitalized freight/customs costs. No stock leaves during the request.
 * The independent reviewer must expense the precise un-recoverable landed
 * portion and credit its original inventory asset in the same transaction
 * that posts the exact source-lot physical return. Base supplier payable
 * reduction remains the separately approved supplier debit note workflow.
 */
class PurchaseReturnLandedCostAdjustmentService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly InventoryStockService $stock,
    ) {}

    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['landed_return'=>$message]);
    }

    public function finalize(int $returnId,array $data,int $reviewerId):object
    {
        return DB::transaction(function()use($returnId,$data,$reviewerId){
            app(FinancePeriodClosingService::class)->holdPostingGate();
            $return=PurchaseReturnRecord::whereKey($returnId)
                ->lockForUpdate()->firstOrFail();
            if($return->status!=='pending_landed_cost_review'){
                $this->reject('Only an unposted supplier return awaiting original landed-cost financial review can be finalized.');
            }
            if((int)$return->created_by===$reviewerId){
                $this->reject('The staff member requesting physical supplier return cannot approve its landed-cost GL adjustment.');
            }
            if(DB::table('purchase_return_landed_cost_adjustments')
                ->where('purchase_return_record_id',$return->id)->exists()){
                $this->reject('This return already has an approved landed-cost financial adjustment.');
            }
            $receipt=$return->receipt()->lockForUpdate()->firstOrFail();
            if($receipt->status!=='posted'){
                $this->reject('Source goods receipt is no longer a posted inventory receipt.');
            }
            $date=$return->returned_on->toDateString();
            $policy=app(ManufacturingLedgerPolicyService::class)
                ->approved((int)$return->currency_id);
            $inventoryId=(int)$policy->material_inventory_ledger_id;
            $expense=DB::table('ledger_accounts')
                ->where('id',$data['expense_ledger_id'])->first();
            if(!$expense || !$expense->is_active || $expense->is_group ||
                $expense->type!=='expense' ||
                (int)$expense->id===(int)$policy->cogs_ledger_id ||
                DB::table('entity_ledger_accounts')
                    ->where('ledger_account_id',$expense->id)->exists()){
                $this->reject('Choose an active standalone non-COGS loss expense ledger for the nonrecoverable landed-cost share.');
            }
            $lines=$return->lines()->lockForUpdate()->get();
            if($lines->isEmpty())$this->reject('The pending return has no original source GRN lines.');
            $result=[];$total=BigDecimal::zero();
            $allocationService=app(GoodsReceiptLandedCostPostingService::class);
            foreach($lines as $line){
                $source=DB::table('goods_receipt_lines')
                    ->where('id',$line->goods_receipt_line_id)->first();
                if(!$source || (int)$source->goods_receipt_id!==(int)$return->goods_receipt_id){
                    $this->reject('Return line has a mismatched original GRN source.');
                }
                $landedRows=DB::table('goods_receipt_landed_cost_allocations as detail')
                    ->join('goods_receipt_landed_costs as component',
                        'component.id','=','detail.goods_receipt_landed_cost_id')
                    ->where('detail.goods_receipt_line_id',$source->id)
                    ->get(['detail.amount','component.currency_code']);
                if($landedRows->isEmpty())continue;
                $allocationService->assertLotCostPosted((int)$source->id,$date,$inventoryId);
                $originalQty=BigDecimal::of((string)$source->quantity);
                if(!$originalQty->isPositive()){
                    $this->reject('Original GRN quantity must be positive before calculating a landed-cost return.');
                }
                $componentTotal=BigDecimal::zero();
                foreach($landedRows as $landed){
                    if((string)$landed->currency_code!==(string)$receipt->cost_currency_code){
                        $this->reject('A supplier-return landed component uses another purchase currency.');
                    }
                    $componentTotal=$componentTotal->plus((string)$landed->amount);
                }
                $prior=DB::table('purchase_return_record_lines as detail')
                    ->join('purchase_return_records as original',
                        'original.id','=','detail.purchase_return_record_id')
                    ->leftJoin('purchase_return_landed_cost_adjustment_lines as posted',
                        'posted.purchase_return_record_line_id','=','detail.id')
                    ->where('original.goods_receipt_id',$return->goods_receipt_id)
                    ->where('detail.goods_receipt_line_id',$source->id)
                    ->where('original.id','!=',$returnId)
                    ->where('original.status','posted_pending_credit')
                    ->get(['detail.quantity','posted.amount']);
                $priorQty=BigDecimal::zero();$priorCost=BigDecimal::zero();
                foreach($prior as $history){
                    if($history->amount===null){
                        $this->reject('An earlier landed-cost supplier return has no independent cost adjustment. Reconcile historic physical and ledger records before another return.');
                    }
                    $priorQty=$priorQty->plus((string)$history->quantity);
                    $priorCost=$priorCost->plus((string)$history->amount);
                }
                $currentQty=BigDecimal::of((string)$line->quantity);
                $cumulative=$priorQty->plus($currentQty);
                if($cumulative->isGreaterThan($originalQty)){
                    $this->reject('Cumulative original-lot supplier returns exceed the posted GRN quantity.');
                }
                $cumulativeCost=$cumulative->isEqualTo($originalQty)
                    ?$componentTotal
                    :$componentTotal->multipliedBy($cumulative)
                        ->dividedBy($originalQty,4,RoundingMode::HalfUp);
                $value=$cumulativeCost->minus($priorCost);
                if(!$value->isPositive()){
                    $this->reject('The returned landed-cost share is too small or conflicts with already posted loss value at 4-decimal journal precision.');
                }
                $result[]=[
                    'purchase_return_record_line_id'=>$line->id,
                    'quantity'=>(string)$currentQty->toScale(6),
                    'amount'=>(string)$value->toScale(4),
                ];
                $total=$total->plus($value);
            }
            if(!$total->isPositive() || $total->isGreaterThan('9999999999999999.9999')){
                $this->reject('Supplier return contains no positive, verifiable original-currency landed-cost value.');
            }
            DocumentSequence::firstOrCreate(['document_type'=>'supplier_return_landed_cost'],[
                'prefix'=>'SRL-','next_number'=>1,'padding'=>7,'is_active'=>true,
            ]);
            $journal=JournalEntry::create([
                'number'=>DocumentSequence::issue('supplier_return_landed_cost'),
                'entry_date'=>$date,
                'reference'=>substr($return->number,0,100),
                'description'=>'Independent nonrecoverable landed-cost return write-off '.$return->number,
                'status'=>'draft','currency_id'=>$return->currency_id,
                'created_by'=>$reviewerId,
            ]);
            $value=(string)$total->toScale(4);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$expense->id,'debit'=>$value,
                    'credit'=>'0','memo'=>'Nonrecoverable landed-cost share of returned inventory'],
                ['ledger_account_id'=>$inventoryId,'debit'=>'0',
                    'credit'=>$value,'memo'=>'Reverse capitalized freight/customs on supplier-return lots'],
            ]);
            $this->posting->post($journal,$reviewerId);
            $adjustmentId=DB::table('purchase_return_landed_cost_adjustments')->insertGetId([
                'purchase_return_record_id'=>$return->id,
                'currency_id'=>$return->currency_id,
                'inventory_ledger_id'=>$inventoryId,
                'expense_ledger_id'=>$expense->id,
                'amount'=>$value,'journal_entry_id'=>$journal->id,
                'approval_reference'=>trim($data['approval_reference']),
                'source_evidence'=>trim($data['source_evidence']),
                'approved_by'=>$reviewerId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($result as $part){
                DB::table('purchase_return_landed_cost_adjustment_lines')->insert([
                    'purchase_return_landed_cost_adjustment_id'=>$adjustmentId,
                    ...$part,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            // Post ALL pending return lines atomically. The InventoryCostService
            // validates this independent header for each landed-cost source.
            foreach($lines as $line){
                $material=InventoryMaterial::whereKey($line->inventory_material_id)->firstOrFail();
                $this->stock->post($material,[
                    'type'=>'adjustment_out','direction'=>'out',
                    'quantity'=>(string)$line->quantity,
                    'company_warehouse_id'=>$return->company_warehouse_id,
                    'reference_type'=>'purchase_return_line','reference_id'=>$line->id,
                    'occurred_on'=>$date,
                    'notes'=>'Finance-reviewed supplier lot return '.$return->number,
                    'created_by'=>$reviewerId,
                ]);
            }
            DB::table('purchase_return_records')->where('id',$return->id)->update([
                'status'=>'posted_pending_credit',
                'financial_status'=>'supplier_credit_pending',
                'updated_at'=>now(),
            ]);
            ActivityLog::record('purchases.return.landed_cost_finance_approved',
                'purchase_return_record',$return->id,
                'Independent finance reviewer expensed the exact source landed-cost share and released physical lot return. Supplier base debit remains separately pending.',[
                    'journal_entry_id'=>$journal->id,'amount'=>$value,
                    'expense_ledger_id'=>$expense->id,'currency_id'=>$return->currency_id,
                    'approval_reference'=>$data['approval_reference'],
                ]);
            return DB::table('purchase_return_landed_cost_adjustments')
                ->where('id',$adjustmentId)->first();
        });
    }
}
