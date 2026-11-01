<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GoodsReceiptLandedCost extends Model
{
    protected $fillable = ['goods_receipt_id', 'component', 'currency_code', 'amount', 'reference', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Landed-cost snapshots cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Landed-cost snapshots cannot be deleted.'));
    }

    public function allocations()
    {
        return $this->hasMany(GoodsReceiptLandedCostAllocation::class);
    }
}
