<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseOrder extends Model {
 protected $fillable=['number','supplier_id','currency_id','source','order_date','expected_date','status','notes','created_by'];
 public function supplier(){return $this->belongsTo(TradeParty::class,'supplier_id');}
 public function currency(){return $this->belongsTo(Currency::class);}
 public function items(){return $this->hasMany(PurchaseOrderItem::class);}
 public function expenses(){return $this->hasMany(PurchaseOrderExpense::class);}
 public function advances(){return $this->hasMany(PurchaseOrderAdvance::class);}
}
