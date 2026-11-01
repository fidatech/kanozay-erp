<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GoodsReceipt extends Model
{
    protected $fillable = [
        'number', 'purchase_order_id', 'receipt_token', 'received_on',
        'supplier_reference', 'status', 'notes', 'created_by',
        'cost_currency_id', 'cost_currency_code',
    ];

    protected $casts = ['received_on' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (self $receipt) {
            if ($receipt->getOriginal('status') === 'posted') {
                throw new LogicException('Posted goods receipts cannot be edited.');
            }
        });
        static::deleting(function (self $receipt) {
            if ($receipt->status === 'posted') {
                throw new LogicException('Posted goods receipts cannot be deleted.');
            }
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
