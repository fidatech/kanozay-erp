<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseOrderAdvance extends Model {
 protected $fillable=['payment_date','amount','reference','notes','created_by'];
 public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
}
