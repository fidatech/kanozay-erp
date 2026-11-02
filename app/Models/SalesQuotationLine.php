<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesQuotationLine extends Model
{
    protected $fillable = ['sales_quotation_id', 'finished_good_id', 'description', 'quantity', 'unit', 'unit_price', 'line_total'];
    protected $casts = ['unit_price' => 'decimal:4', 'line_total' => 'decimal:4'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued quotation lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued quotation lines cannot be deleted.'));
    }
    public function quotation() { return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id'); }
    public function finishedGood() { return $this->belongsTo(FinishedGood::class); }
}
