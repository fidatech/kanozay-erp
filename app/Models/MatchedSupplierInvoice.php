<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MatchedSupplierInvoice extends Model
{
    protected $fillable = [
        'number','submission_token','supplier_id','purchase_order_id','goods_receipt_id',
        'currency_id','supplier_invoice_number','invoice_date','due_date','total','status',
        'inventory_ledger_id','trade_document_id','journal_entry_id','created_by',
    ];
    protected $casts=['invoice_date'=>'date','due_date'=>'date','total'=>'decimal:4'];
    protected static function booted():void {
        static::updating(fn()=>throw new LogicException('Posted matched supplier invoices are immutable.'));
        static::deleting(fn()=>throw new LogicException('Matched supplier invoices cannot be deleted.'));
    }
    public function lines(){return $this->hasMany(MatchedSupplierInvoiceLine::class);}
    public function supplier(){return $this->belongsTo(TradeParty::class,'supplier_id');}
    public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
    public function receipt(){return $this->belongsTo(GoodsReceipt::class,'goods_receipt_id');}
    public function currency(){return $this->belongsTo(Currency::class,'currency_id');}
    public function journal(){return $this->belongsTo(JournalEntry::class,'journal_entry_id');}
    public function payable(){return $this->belongsTo(TradeDocument::class,'trade_document_id');}
}