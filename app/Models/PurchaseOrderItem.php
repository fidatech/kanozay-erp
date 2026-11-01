<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseOrderItem extends Model {
 protected $fillable=['description','quantity','unit_price','inventory_material_id'];
 public function inventoryMaterial(){return $this->belongsTo(InventoryMaterial::class,'inventory_material_id');}
 public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
}
