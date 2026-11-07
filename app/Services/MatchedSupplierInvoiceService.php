<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MatchedSupplierInvoice;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MatchedSupplierInvoiceService
{
    public function __construct(private readonly JournalPostingService $posting){}

    public function remaining(GoodsReceipt $receipt):array
    {
        $lines=$receipt->lines()->orderBy('id')->get();
        $billed=DB::table('matched_supplier_invoice_lines')
            ->whereIn('goods_receipt_line_id',$lines->pluck('id'))->get()->groupBy('goods_receipt_line_id');
        $returned=DB::table('purchase_return_record_lines')
            ->whereIn('goods_receipt_line_id',$lines->pluck('id'))->get()->groupBy('goods_receipt_line_id');
        $remaining=[];
        foreach($lines as $line){
            $left=BigDecimal::of((string)$line->quantity);
            foreach($billed->get($line->id,collect()) as $row)$left=$left->minus((string)$row->quantity);
            foreach($returned->get($line->id,collect()) as $row)$left=$left->minus((string)$row->quantity);
            $remaining[$line->id]=(string)$left->toScale(6);
        }
        return $remaining;
    }

    public function post(GoodsReceipt $receipt,array $data,int $actorId):MatchedSupplierInvoice
    {
        return DB::transaction(function()use($receipt,$data,$actorId){
            $locked=GoodsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='posted')
                throw ValidationException::withMessages(['goods_receipt_id'=>'Only posted goods receipts can be matched.']);
            $order=$locked->purchaseOrder()->with('supplier','currency')->firstOrFail();
            $supplier=TradeParty::whereKey($order->supplier_id)->lockForUpdate()->firstOrFail();
            if(!$supplier->is_active||$supplier->type!=='supplier')
                throw ValidationException::withMessages(['supplier'=>'Purchase supplier must be active.']);
            if(!$supplier->currencies()->whereKey($order->currency_id)->exists())
                throw ValidationException::withMessages(['currency_id'=>'Supplier is not enabled for this purchase currency.']);
            $duplicate=MatchedSupplierInvoice::where('submission_token',$data['submission_token'])->first();
            if($duplicate){
                // A retry may only return a previously posted invoice if its full
                // financial payload is identical. A matching total alone does not
                // prove that the same receipt lines were invoiced.
                $submittedQuantities=collect($data['quantities'])
                    ->filter(fn($quantity)=>$quantity!==null && $quantity!=='' &&
                        BigDecimal::of((string)$quantity)->isPositive())
                    ->mapWithKeys(fn($quantity,$id)=>[
                        (string)(int)$id=>(string)BigDecimal::of((string)$quantity)->toScale(6),
                    ])->sortKeys()->all();
                $postedQuantities=$duplicate->lines()->get()
                    ->mapWithKeys(fn($line)=>[
                        (string)$line->goods_receipt_line_id=>
                            (string)BigDecimal::of((string)$line->quantity)->toScale(6),
                    ])->sortKeys()->all();
                if((int)$duplicate->goods_receipt_id===(int)$locked->id &&
                    $duplicate->supplier_invoice_number===$data['supplier_invoice_number'] &&
                    $duplicate->invoice_date->toDateString()===$data['invoice_date'] &&
                    $duplicate->due_date->toDateString()===$data['due_date'] &&
                    (int)$duplicate->inventory_ledger_id===(int)$data['inventory_ledger_id'] &&
                    BigDecimal::of((string)$duplicate->total)->isEqualTo((string)$data['invoice_total']) &&
                    $submittedQuantities===$postedQuantities)
                    return $duplicate;
                throw ValidationException::withMessages(['submission_token'=>'Token already belongs to another supplier invoice.']);
            }
            if(MatchedSupplierInvoice::where('supplier_id',$supplier->id)
                ->where('supplier_invoice_number',$data['supplier_invoice_number'])->exists())
                throw ValidationException::withMessages(['supplier_invoice_number'=>'This supplier invoice number was already recorded.']);
            $inventory=LedgerAccount::whereKey($data['inventory_ledger_id'])
                ->where('type','asset')->where('is_group',false)->where('is_active',true)->first();
            if(!$inventory||DB::table('entity_ledger_accounts')->where('ledger_account_id',$inventory->id)->exists())
                throw ValidationException::withMessages(['inventory_ledger_id'=>'Select an active, independent inventory asset ledger.']);
            $payable=DB::table('entity_ledger_accounts')->where([
                'entity_type'=>'supplier','entity_id'=>$supplier->id,
                'currency_id'=>$order->currency_id,'purpose'=>'payable',
            ])->value('ledger_account_id');
            if(!$payable)
                throw ValidationException::withMessages(['supplier'=>'Supplier payable ledger must be provisioned before invoicing.']);
            $rows=$locked->lines()->lockForUpdate()->get()->keyBy('id');
            $available=$this->remaining($locked);
            $items=$order->items()->get()->keyBy('id');
            $prepared=[];$total=BigDecimal::zero();
            foreach($data['quantities'] as $lineId=>$raw){
                if($raw===null||$raw==='')continue;
                if(!ctype_digit((string)$lineId)||!$rows->has((int)$lineId))
                    throw ValidationException::withMessages(['quantities'=>'Invoice contains a line outside the selected goods receipt.']);
                $qty=BigDecimal::of((string)$raw);
                if(!$qty->isPositive())continue;
                $line=$rows[(int)$lineId];
                if($line->unit_price_snapshot===null)
                    throw ValidationException::withMessages(['quantities'=>'Original receipt unit price missing. Review source before billing.']);
                $sourceItem=$items->get($line->purchase_order_item_id);
                if(!$sourceItem||$sourceItem->inventory_material_id!==$line->inventory_material_id ||
                    BigDecimal::of((string)$sourceItem->unit_price)->compareTo((string)$line->unit_price_snapshot)!==0)
                    throw ValidationException::withMessages(['quantities'=>'PO and goods receipt material or purchase price do not match.']);
                if($qty->isGreaterThan((string)($available[$lineId]??'0')))
                    throw ValidationException::withMessages(['quantities'=>'Supplier invoice exceeds unbilled, non-returned receipt quantity.']);
                $value=$qty->multipliedBy((string)$line->unit_price_snapshot)->toScale(10);
                $total=$total->plus($value);
                $prepared[]=['goods_receipt_line_id'=>$line->id,
                    'inventory_material_id'=>$line->inventory_material_id,
                    'quantity'=>(string)$qty->toScale(6),
                    'unit_price_snapshot'=>(string)$line->unit_price_snapshot,
                    'line_value'=>(string)$value];
            }
            $total=$total->toScale(4,RoundingMode::HalfUp);
            if(!$prepared||!$total->isPositive()||$total->isGreaterThan(BigDecimal::of('9999999999999999.9999')))
                throw ValidationException::withMessages(['quantities'=>'Invoice requires positive, supported payable amount.']);
            if(!$total->isEqualTo((string)$data['invoice_total']))
                throw ValidationException::withMessages(['invoice_total'=>'Supplier invoice total does not match receipt quantity × original PO price. Check freight, tax or discounts separately.']);

            $draftNumber='TMP-'.Str::uuid();
            $journal=JournalEntry::create([
                'number'=>$draftNumber,'entry_date'=>$data['invoice_date'],
                'reference'=>$data['supplier_invoice_number'],
                'description'=>'Matched supplier invoice '.$data['supplier_invoice_number'].' against '.$locked->number,
                'currency_id'=>$order->currency_id,'created_by'=>$actorId,'status'=>'draft',
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$inventory->id,'debit'=>(string)$total,'credit'=>'0','memo'=>'Matched inventory purchase'],
                ['ledger_account_id'=>$payable,'debit'=>'0','credit'=>(string)$total,'memo'=>'Supplier payable accrual'],
            ]);
            $this->posting->post($journal,$actorId);

            $document=TradeDocument::create([
                'type'=>'payable','number'=>$draftNumber,'trade_party_id'=>$supplier->id,
                'currency_id'=>$order->currency_id,'document_date'=>$data['invoice_date'],
                'due_date'=>$data['due_date'],'amount'=>(string)$total,
                'reference'=>$data['supplier_invoice_number'],
                'description'=>'Matched supplier invoice '.$data['supplier_invoice_number'].' / GRN '.$locked->number,
                'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
            ]);
            $invoice=MatchedSupplierInvoice::create([
                'number'=>$draftNumber,'submission_token'=>$data['submission_token'],
                'supplier_id'=>$supplier->id,'purchase_order_id'=>$order->id,
                'goods_receipt_id'=>$locked->id,'currency_id'=>$order->currency_id,
                'supplier_invoice_number'=>$data['supplier_invoice_number'],
                'invoice_date'=>$data['invoice_date'],'due_date'=>$data['due_date'],
                'total'=>(string)$total,'status'=>'posted',
                'inventory_ledger_id'=>$inventory->id,
                'trade_document_id'=>$document->id,'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
            ]);
            $invoice->lines()->createMany($prepared);
            $number='SIN-'.str_pad((string)$invoice->id,7,'0',STR_PAD_LEFT);
            DB::table('matched_supplier_invoices')->where('id',$invoice->id)->update([
                'number'=>$number,'updated_at'=>now(),
            ]);
            DB::table('trade_documents')->where('id',$document->id)->update(['number'=>$number,'updated_at'=>now()]);
            DB::table('journal_entries')->where('id',$journal->id)->update(['number'=>$number,'updated_at'=>now()]);
            ActivityLog::record('supplier.invoice.matched','matched_supplier_invoice',$invoice->id,
                'GRN-based supplier payable recognized: '.$number,
                ['grn'=>$locked->number,'supplier_invoice'=>$data['supplier_invoice_number'],'total'=>(string)$total]);
            return $invoice->fresh();
        });
    }
}