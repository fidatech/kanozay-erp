<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesInvoiceLine extends Model
{
    protected $fillable = [
        'sales_invoice_id', 'finished_good_id', 'production_batch_id', 'dispatch_id',
        'quantity_packages', 'unit_price', 'line_total',
    ];

    protected $casts = ['unit_price' => 'decimal:4', 'line_total' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sales invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Sales invoice lines cannot be deleted.'));
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function finishedGood()
    {
        return $this->belongsTo(FinishedGood::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function dispatch()
    {
        return $this->belongsTo(FinishedGoodDispatch::class, 'dispatch_id');
    }
}
