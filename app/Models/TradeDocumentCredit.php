<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TradeDocumentCredit extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = ['trade_document_id', 'sales_credit_note_id', 'supplier_debit_note_id', 'supplier_advance_application_id', 'customer_advance_application_id', 'credit_date', 'amount'];
    protected $casts = ['credit_date' => 'date', 'amount' => 'decimal:4'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted trade document credits are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted trade document credits cannot be deleted.'));
    }
    public function document() { return $this->belongsTo(TradeDocument::class, 'trade_document_id'); }
    public function creditNote() { return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id'); }
}
