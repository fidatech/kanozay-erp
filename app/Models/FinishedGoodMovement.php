<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinishedGoodMovement extends Model
{
    protected $fillable = ['production_batch_id', 'type', 'quantity_packages', 'occurred_on', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finished goods movements cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Finished goods movements cannot be deleted.'));
    }

    public function finishedGood()
    {
        return $this->belongsTo(FinishedGood::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }
}
