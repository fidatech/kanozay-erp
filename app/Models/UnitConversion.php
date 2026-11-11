<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitConversion extends Model
{
    protected $fillable = [
        'from_unit', 'to_unit', 'factor', 'inventory_material_id', 'is_active', 'notes',
        'created_by', 'deactivated_by', 'deactivated_at',
    ];

    protected $casts = ['is_active' => 'boolean', 'deactivated_at' => 'datetime'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(InventoryMaterial::class, 'inventory_material_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
