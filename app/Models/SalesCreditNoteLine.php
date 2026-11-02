<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesCreditNoteLine extends Model
{
    protected $fillable = ['sales_credit_note_id', 'sales_invoice_line_id', 'finished_good_id', 'production_batch_id', 'quantity_packages', 'unit_price', 'line_total', 'restocked'];
    protected $casts = ['unit_price' => 'decimal:4', 'line_total' => 'decimal:4', 'restocked' => 'boolean'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit note lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Credit note lines cannot be deleted.'));
    }
    public function invoiceLine() { return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id'); }
}
