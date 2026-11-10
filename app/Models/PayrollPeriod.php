<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'period_start', 'currency_id', 'status', 'expense_ledger_id', 'payable_ledger_id',
        'approval_journal_id', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = ['period_start' => 'date', 'approved_at' => 'datetime'];

    public function currency() { return $this->belongsTo(Currency::class); }
    public function lines() { return $this->hasMany(PayrollLine::class); }
    public function approvalJournal() { return $this->belongsTo(JournalEntry::class, 'approval_journal_id'); }
}
