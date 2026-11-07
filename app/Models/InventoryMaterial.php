<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Brick\Math\BigDecimal;

class InventoryMaterial extends Model
{
    protected $fillable = ['sku', 'name', 'unit', 'is_packaging', 'is_active', 'catalog_unit_id', 'catalog_category_id'];

    protected $casts = [
        'is_packaging' => 'boolean',
        'is_active' => 'boolean',
        'on_hand_quantity' => 'decimal:6',
        'reserved_quantity' => 'decimal:6',
    ];

    public function getAvailableQuantityAttribute(): string
    {
        return (string) BigDecimal::of((string) $this->on_hand_quantity)
            ->minus(BigDecimal::of((string) $this->reserved_quantity))->toScale(6);
    }

    public function catalogUnit(){return $this->belongsTo(OperationalRegister::class,'catalog_unit_id');}
    public function catalogCategory(){return $this->belongsTo(OperationalRegister::class,'catalog_category_id');}

    public function movements()
    {
        return $this->hasMany(InventoryStockMovement::class);
    }
}
