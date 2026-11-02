<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesOrder extends Model
{
    protected $fillable = ['number', 'submission_token', 'sales_quotation_id', 'customer_id', 'currency_id', 'currency_code', 'order_date', 'status', 'total', 'created_by'];
    protected $casts = ['order_date' => 'date', 'total' => 'decimal:4'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Confirmed sales orders are immutable. Use a controlled fulfilment or cancellation workflow.'));
        static::deleting(fn () => throw new LogicException('Sales orders cannot be deleted.'));
    }
    public function quotation() { return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id'); }
    public function customer() { return $this->belongsTo(TradeParty::class, 'customer_id'); }
    public function currency() { return $this->belongsTo(Currency::class); }
    public function lines() { return $this->hasMany(SalesOrderLine::class)->orderBy('id'); }
}
