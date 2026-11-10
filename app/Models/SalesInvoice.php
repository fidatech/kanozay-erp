<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesInvoice extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'number', 'submission_token', 'customer_id', 'currency_id', 'currency_code', 'invoice_date', 'due_date',
        'status', 'subtotal', 'total', 'journal_entry_id', 'trade_document_id', 'created_by',
    ];

    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(function (SalesInvoice $invoice) {
            if ($invoice->getOriginal('status') === 'posted') throw new LogicException('Posted sales invoices are immutable. Use an approved credit note workflow.');
        });
        static::deleting(fn () => throw new LogicException('Sales invoices cannot be deleted.'));
    }

    public function customer()
    {
        return $this->belongsTo(TradeParty::class, 'customer_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function tradeDocument()
    {
        return $this->belongsTo(TradeDocument::class, 'trade_document_id');
    }

    public function creditNotes()
    {
        return $this->hasMany(SalesCreditNote::class, 'sales_invoice_id')->orderBy('id');
    }
}
