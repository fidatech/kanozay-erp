<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryStockCountLine extends Model
{
    protected $fillable = [
        'inventory_material_id', 'expected_quantity', 'counted_quantity',
        'idempotency_key', 'inventory_stock_adjustment_id',
    ];

    protected $casts = ['expected_quantity' => 'decimal:6', 'counted_quantity' => 'decimal:6'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Stock count lines must not be deleted.'));
        static::updating(function (self $line) {
            if ($line->count()->where('status', 'approved')->exists()) {
                throw new LogicException('Approved stock count lines are immutable.');
            }
        });
    }

    public function count()
    {
        return $this->belongsTo(InventoryStockCount::class, 'inventory_stock_count_id');
    }

    public function material()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(InventoryStockAdjustment::class, 'inventory_stock_adjustment_id');
    }
}
