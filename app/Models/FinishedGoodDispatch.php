<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinishedGoodDispatch extends Model
{
    protected $fillable = ['number', 'submission_token', 'dispatch_date', 'reference', 'notes', 'created_by'];
    protected $casts = ['dispatch_date' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted finished-goods dispatches are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted finished-goods dispatches cannot be deleted.'));
    }

    public function lines()
    {
        return $this->hasMany(FinishedGoodDispatchLine::class, 'finished_good_dispatch_id');
    }
}
