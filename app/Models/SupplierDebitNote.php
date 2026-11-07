<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class SupplierDebitNote extends Model {
 protected $fillable=[
  'number','submission_token','purchase_return_record_id','trade_document_id','journal_entry_id',
  'credit_offset_ledger_id','currency_id','credit_date','amount',
  'supplier_credit_reference','review_notes','created_by',
 ];
 protected $casts=['credit_date'=>'date','amount'=>'decimal:4'];
 protected static function booted():void {
  static::updating(fn()=>throw new LogicException('Posted supplier debit notes cannot be edited.'));
  static::deleting(fn()=>throw new LogicException('Posted supplier debit notes cannot be deleted.'));
 }
 public function purchaseReturn(){return $this->belongsTo(PurchaseReturnRecord::class,'purchase_return_record_id');}
 public function document(){return $this->belongsTo(TradeDocument::class,'trade_document_id');}
 public function journal(){return $this->belongsTo(JournalEntry::class,'journal_entry_id');}
 public function currency(){return $this->belongsTo(Currency::class,'currency_id');}
}
