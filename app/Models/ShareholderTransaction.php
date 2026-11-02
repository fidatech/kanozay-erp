<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ShareholderTransaction extends Model
{
    protected $fillable = [
        'number', 'idempotency_key', 'shareholder_id', 'type', 'transaction_date',
        'currency_id', 'financial_account_id', 'amount', 'reference', 'description',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = ['transaction_date' => 'date', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted shareholder transactions are immutable. Post a controlled reversal.'));
        static::deleting(fn () => throw new LogicException('Posted shareholder transactions cannot be deleted.'));
    }

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
