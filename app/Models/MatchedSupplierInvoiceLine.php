<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MatchedSupplierInvoiceLine extends Model
{
    protected $fillable=[
        'goods_receipt_line_id','inventory_material_id','quantity','unit_price_snapshot','line_value',
    ];
    protected $casts=['quantity'=>'decimal:6','unit_price_snapshot'=>'decimal:4','line_value'=>'decimal:10'];
    protected static function booted():void {
        static::updating(fn()=>throw new LogicException('Matched supplier invoice line history is immutable.'));
        static::deleting(fn()=>throw new LogicException('Matched supplier invoice line history cannot be deleted.'));
    }
    public function source(){return $this->belongsTo(GoodsReceiptLine::class,'goods_receipt_line_id');}
    public function material(){return $this->belongsTo(InventoryMaterial::class,'inventory_material_id');}
    public function invoice(){return $this->belongsTo(MatchedSupplierInvoice::class,'matched_supplier_invoice_id');}
}