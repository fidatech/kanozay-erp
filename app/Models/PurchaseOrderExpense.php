<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseOrderExpense extends Model {
 protected $fillable=['category','description','amount','expense_date','created_by'];
 public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
}
