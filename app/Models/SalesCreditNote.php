<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesCreditNote extends Model
{
    protected $fillable = ['number', 'submission_token', 'sales_invoice_id', 'trade_document_id', 'journal_entry_id', 'credit_date', 'reason', 'currency_code', 'total', 'created_by'];
    protected $casts = ['credit_date' => 'date', 'total' => 'decimal:4'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted credit notes are immutable.'));
        static::deleting(fn () => throw new LogicException('Credit notes cannot be deleted.'));
    }
    public function invoice() { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function lines() { return $this->hasMany(SalesCreditNoteLine::class, 'sales_credit_note_id'); }
    public function journal() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function refund(){return $this->hasOne(SalesCreditNoteRefund::class,'sales_credit_note_id');}
    public function tradeDocumentCredit() { return $this->hasOne(TradeDocumentCredit::class); }
}
