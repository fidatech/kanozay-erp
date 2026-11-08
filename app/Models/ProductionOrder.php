<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $fillable = [
        'submission_token', 'number', 'production_recipe_id', 'package_count', 'status', 'notes', 'created_by',
        'recipe_name_snapshot', 'product_code_snapshot',
        'package_weight_kg_snapshot', 'package_unit_snapshot',
    ];

    protected $casts = ['package_weight_kg_snapshot' => 'decimal:4'];

    public function materials()
    {
        return $this->hasMany(ProductionOrderMaterial::class)->orderBy('line_number');
    }

    public function batch()
    {
        return $this->hasOne(ProductionBatch::class);
    }

    public function recipe()
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function getPlannedRecipeNameAttribute(): ?string
    {
        return $this->recipe_name_snapshot ?? $this->recipe?->name;
    }

    public function getPlannedPackageWeightKgAttribute(): ?string
    {
        return $this->package_weight_kg_snapshot ?? $this->recipe?->package_weight_kg;
    }

    public function getPlannedPackageUnitAttribute(): ?string
    {
        return $this->package_unit_snapshot ?? $this->recipe?->package_unit;
    }

    public function getPlannedProductCodeAttribute(): ?string
    {
        // A NULL product code is a valid captured value for a new plan.
        // Legacy orders are distinguishable by their absent weight snapshot.
        return $this->package_weight_kg_snapshot !== null
            ? $this->product_code_snapshot
            : $this->recipe?->product_code;
    }
}
