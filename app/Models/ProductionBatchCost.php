<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionBatchCost extends Model
{
    protected $fillable = [
        'production_batch_id', 'currency_id', 'currency_code', 'material_cost',
        'consumed_quantity', 'unvalued_quantity', 'ingredient_cost', 'packaging_cost',
    ];

    protected $casts = [
        'material_cost' => 'decimal:10',
        'consumed_quantity' => 'decimal:6',
        'unvalued_quantity' => 'decimal:6',
        'ingredient_cost' => 'decimal:10',
        'packaging_cost' => 'decimal:10',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Production batch cost snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Production batch cost snapshots cannot be deleted.'));
    }

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }
}
