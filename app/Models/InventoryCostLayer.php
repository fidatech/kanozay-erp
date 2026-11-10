<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostLayer extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'inventory_material_id', 'source_type', 'source_id', 'original_quantity',
        'remaining_quantity', 'original_cost', 'currency_id', 'currency_code',
        'received_on', 'created_by',
    ];

    protected $casts = [
        'original_quantity' => 'decimal:6',
        'remaining_quantity' => 'decimal:6',
        'original_cost' => 'decimal:10',
        'received_on' => 'date',
    ];

    public function material()
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }

    public function consumptions()
    {
        return $this->hasMany(InventoryCostConsumption::class);
    }
}
