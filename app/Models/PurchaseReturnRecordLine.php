<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PurchaseReturnRecordLine extends Model
{
    protected $fillable=['goods_receipt_line_id','inventory_material_id','quantity','unit_price_snapshot','supplier_credit_value_snapshot'];
    protected $casts=['quantity'=>'decimal:6','unit_price_snapshot'=>'decimal:4','supplier_credit_value_snapshot'=>'decimal:10'];
    protected static function booted():void {
        static::updating(fn()=>throw new LogicException('Posted purchase return lines are immutable.'));
        static::deleting(fn()=>throw new LogicException('Posted purchase return lines cannot be deleted.'));
    }
    public function material(){return $this->belongsTo(InventoryMaterial::class,'inventory_material_id');}
    public function receiptLine(){return $this->belongsTo(GoodsReceiptLine::class,'goods_receipt_line_id');}
}
