<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatch extends Model
{
    protected $fillable = [
        'production_order_id', 'finished_good_id', 'batch_number',
        'reported_packages', 'notes', 'submitted_by',
    ];

    protected $casts = ['decided_at' => 'datetime', 'released_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function finishedGood()
    {
        return $this->belongsTo(FinishedGood::class);
    }

    public function checks()
    {
        return $this->hasMany(ProductionBatchCheck::class)->orderBy('id');
    }

    public function receipt()
    {
        return $this->hasOne(FinishedGoodMovement::class);
    }
}
