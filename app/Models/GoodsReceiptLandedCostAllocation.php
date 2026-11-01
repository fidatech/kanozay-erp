<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GoodsReceiptLandedCostAllocation extends Model
{
    protected $fillable = ['goods_receipt_landed_cost_id', 'goods_receipt_line_id', 'amount'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Landed-cost allocations cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Landed-cost allocations cannot be deleted.'));
    }
}
