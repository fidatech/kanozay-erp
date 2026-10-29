<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionRecipe extends Model {
    protected $fillable = ['name', 'product_code', 'method', 'package_weight_kg', 'package_unit', 'cost_currency_code', 'source_sheet', 'source_reference', 'status', 'notes', 'created_by'];
    public function lines() { return $this->hasMany(ProductionRecipeLine::class)->orderBy('line_number'); }
}
