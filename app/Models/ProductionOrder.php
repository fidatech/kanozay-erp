<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model {
    protected $fillable = ['number', 'production_recipe_id', 'package_count', 'status', 'notes', 'created_by'];
    public function recipe() { return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id'); }
}
