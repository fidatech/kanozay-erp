<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderMaterial extends Model
{
    protected $fillable = [
        'line_number', 'material_name', 'unit', 'quantity_per_package',
        'required_quantity', 'is_packaging', 'inventory_material_id',
        'reserved_quantity', 'issued_quantity',
    ];

    protected $casts = [
        'quantity_per_package' => 'decimal:6',
        'required_quantity' => 'decimal:6',
        'reserved_quantity' => 'decimal:6',
        'issued_quantity' => 'decimal:6',
        'is_packaging' => 'boolean',
    ];

    public function wastes()
    {
        return $this->hasMany(ProductionMaterialWaste::class, 'production_order_material_id');
    }

    public function inventoryMaterial()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
