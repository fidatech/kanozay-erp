<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionRecipeLine extends Model {
    protected $fillable = ['line_number', 'material_name', 'unit', 'quantity_per_package', 'unit_cost', 'is_packaging', 'source_cell'];
    protected $casts = ['is_packaging' => 'boolean'];
    public function recipe() { return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id'); }
}
