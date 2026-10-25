<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TradeSettlementAllocation extends Model {
 protected $fillable=['trade_document_id','money_transaction_id','amount','created_by'];
 public function document(){return $this->belongsTo(TradeDocument::class,'trade_document_id');}
 public function transaction(){return $this->belongsTo(MoneyTransaction::class,'money_transaction_id');}
}
