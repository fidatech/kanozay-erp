<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GoodsReceiptLine extends Model
{
    protected $fillable = ['purchase_order_item_id', 'inventory_material_id', 'quantity', 'unit'];

    protected $casts = ['quantity' => 'decimal:6'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted receipt lines cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Posted receipt lines cannot be deleted.'));
    }

    public function receipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function material()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }
}
