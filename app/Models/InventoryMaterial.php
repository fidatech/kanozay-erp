<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMaterial extends Model
{
    protected $fillable = ['sku', 'name', 'unit', 'is_packaging', 'is_active'];

    protected $casts = [
        'is_packaging' => 'boolean',
        'is_active' => 'boolean',
        'on_hand_quantity' => 'decimal:6',
        'reserved_quantity' => 'decimal:6',
    ];

    public function movements()
    {
        return $this->hasMany(InventoryStockMovement::class);
    }
}
