<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinishedGoodDispatchLine extends Model
{
    protected $fillable = ['finished_good_dispatch_id', 'finished_good_id', 'production_batch_id', 'quantity_packages'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted finished-goods dispatch lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted finished-goods dispatch lines cannot be deleted.'));
    }

    public function dispatch()
    {
        return $this->belongsTo(FinishedGoodDispatch::class, 'finished_good_dispatch_id');
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
