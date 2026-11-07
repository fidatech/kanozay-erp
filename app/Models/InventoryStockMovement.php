<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryStockMovement extends Model
{
    protected $fillable = [
        'type', 'direction', 'quantity', 'reference_type', 'reference_id',
        'occurred_on', 'notes', 'created_by', 'company_warehouse_id',
    ];

    protected $casts = ['quantity' => 'decimal:6', 'occurred_on' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock ledger entries cannot be edited. Post a correcting movement.'));
        static::deleting(fn () => throw new LogicException('Stock ledger entries cannot be deleted. Post a correcting movement.'));
    }

    public function material()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }
}
