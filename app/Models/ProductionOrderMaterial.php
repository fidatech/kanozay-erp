<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderMaterial extends Model
{
    protected $fillable = [
        'line_number', 'material_name', 'unit', 'quantity_per_package',
        'required_quantity', 'is_packaging',
    ];

    protected $casts = ['is_packaging' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
