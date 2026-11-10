<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Brick\Math\BigDecimal;

class GoodsReceipt extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'number', 'purchase_order_id', 'receipt_token', 'received_on',
        'supplier_reference', 'status', 'notes', 'created_by', 'company_warehouse_id',
        'cost_currency_id', 'cost_currency_code',
        'valuation_currency_id', 'valuation_currency_code',
        'fx_rate_snapshot', 'fx_rate_source_id', 'fx_rate_effective_date',
    ];

    protected $casts = [
        'received_on' => 'date',
        'fx_rate_snapshot' => 'decimal:12',
        'fx_rate_effective_date' => 'date',
    ];

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

    /**
     * Purchase-only snapshot, in the original PO currency. This is not a
     * landed-cost valuation or a general-ledger amount.
     */
    public function getPurchaseValueSnapshotAttribute(): ?string
    {
        if (! $this->cost_currency_code) {
            return null;
        }

        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->line_subtotal_snapshot === null)) {
            return null;
        }

        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $total = $total->plus((string) $line->line_subtotal_snapshot);
        }

        return rtrim(rtrim((string) $total->toScale(10), '0'), '.').' '.$this->cost_currency_code;
    }

    /**
     * Base-currency purchase equivalent only, not landed-cost valuation.
     * Both original line costs and the FX rate are frozen at GRN posting.
     */
    public function getBasePurchaseValueSnapshotAttribute(): ?string
    {
        if (! $this->valuation_currency_code || $this->fx_rate_snapshot === null) {
            return null;
        }

        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->line_subtotal_snapshot === null)) {
            return null;
        }

        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $total = $total->plus((string) $line->line_subtotal_snapshot);
        }
        $converted = $total->multipliedBy((string) $this->fx_rate_snapshot);

        return rtrim(rtrim((string) $converted->toScale(22), '0'), '.').' '.$this->valuation_currency_code;
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
