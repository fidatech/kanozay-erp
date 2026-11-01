<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionBatchCheck extends Model
{
    protected $fillable = ['test_name', 'specification', 'observation', 'result', 'recorded_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Laboratory checks cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Laboratory checks cannot be deleted.'));
    }

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }
}
