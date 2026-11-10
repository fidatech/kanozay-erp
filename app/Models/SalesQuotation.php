<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesQuotation extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = ['number', 'submission_token', 'customer_id', 'currency_id', 'currency_code', 'quotation_date', 'valid_until', 'status', 'subtotal', 'total', 'reference', 'notes', 'created_by'];

    protected $casts = ['quotation_date' => 'date', 'valid_until' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(function (SalesQuotation $quotation) {
            $stateOnlyAcceptance = $quotation->getOriginal('status') === 'issued'
                && $quotation->status === 'accepted'
                && $quotation->getDirty() === ['status' => 'accepted'];
            if (! $stateOnlyAcceptance) throw new LogicException('Issued quotations are immutable. Create a revised quotation for changes.');
        });
        static::deleting(fn () => throw new LogicException('Issued quotations cannot be deleted.'));
    }

    public function customer() { return $this->belongsTo(TradeParty::class, 'customer_id'); }
    public function currency() { return $this->belongsTo(Currency::class); }
    public function lines() { return $this->hasMany(SalesQuotationLine::class)->orderBy('id'); }
}
