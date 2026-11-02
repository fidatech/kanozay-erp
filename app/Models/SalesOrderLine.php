<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesOrderLine extends Model
{
    protected $fillable = ['sales_order_id', 'sales_quotation_line_id', 'finished_good_id', 'description', 'quantity', 'unit', 'unit_price', 'line_total'];
    protected $casts = ['unit_price' => 'decimal:4', 'line_total' => 'decimal:4'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sales order lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Sales order lines cannot be deleted.'));
    }
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function quotationLine() { return $this->belongsTo(SalesQuotationLine::class, 'sales_quotation_line_id'); }
    public function finishedGood() { return $this->belongsTo(FinishedGood::class); }
    public function invoiceLines() { return $this->hasMany(SalesInvoiceLine::class, 'sales_order_line_id'); }
}
