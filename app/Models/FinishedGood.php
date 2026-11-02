<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinishedGood extends Model
{
    protected $fillable = ['sku', 'name', 'package_unit', 'package_weight_kg', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'package_weight_kg' => 'decimal:3'];

    public function movements()
    {
        return $this->hasMany(FinishedGoodMovement::class);
    }

    public function dispatchLines()
    {
        return $this->hasMany(FinishedGoodDispatchLine::class);
    }
}
