<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class SalesCreditNoteRefund extends Model {
 protected $guarded=[];
 protected $casts=['amount'=>'decimal:4'];
 protected static function booted():void {
  static::updating(fn()=>throw new LogicException('Posted refund records are immutable.'));
  static::deleting(fn()=>throw new LogicException('Posted refund records cannot be deleted.'));
 }
 public function transaction(){return $this->belongsTo(MoneyTransaction::class,'money_transaction_id');}
 public function source(){return $this->belongsTo(FinancialAccount::class,'source_account_id');}
 public function creditNote(){return $this->belongsTo(SalesCreditNote::class,'sales_credit_note_id');}
}