<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionMaterialWaste extends Model
{
    protected $fillable = ['submission_token', 'quantity', 'reason', 'recorded_by'];

    protected $casts = ['quantity' => 'decimal:6'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Production waste records cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Production waste records cannot be deleted.'));
    }

    public function materialLine()
    {
        return $this->belongsTo(ProductionOrderMaterial::class, 'production_order_material_id');
    }
}
