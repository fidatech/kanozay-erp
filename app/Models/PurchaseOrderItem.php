<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseOrderItem extends Model {
 protected $fillable=['description','quantity','unit_price'];
 public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
}
