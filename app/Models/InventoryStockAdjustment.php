<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryStockAdjustment extends Model
{
    protected $fillable = [
        'inventory_material_id', 'direction', 'quantity', 'occurred_on',
        'reason', 'idempotency_key', 'created_by',
    ];

    protected $casts = ['quantity' => 'decimal:6', 'occurred_on' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted stock adjustments cannot be edited. Post a correcting adjustment.'));
        static::deleting(fn () => throw new LogicException('Posted stock adjustments cannot be deleted. Post a correcting adjustment.'));
    }

    public function material()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }
}
