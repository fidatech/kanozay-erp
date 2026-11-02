<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryCostConsumption extends Model
{
    protected $fillable = [
        'inventory_stock_movement_id', 'inventory_cost_layer_id', 'quantity',
        'amount', 'currency_id', 'currency_code', 'is_valued',
    ];

    protected $casts = ['quantity' => 'decimal:6', 'amount' => 'decimal:10', 'is_valued' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory cost consumptions are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory cost consumptions cannot be deleted.'));
    }

    public function movement()
    {
        return $this->belongsTo(InventoryStockMovement::class, 'inventory_stock_movement_id');
    }

    public function layer()
    {
        return $this->belongsTo(InventoryCostLayer::class, 'inventory_cost_layer_id');
    }
}
