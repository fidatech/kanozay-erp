<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryAdvance extends Model
{
    protected $fillable = [
        'employee_profile_id', 'currency_id', 'amount', 'issued_on', 'receivable_ledger_id',
        'money_transaction_id', 'submission_token', 'reason', 'created_by',
    ];
    protected $casts = ['issued_on' => 'date', 'amount' => 'decimal:4'];
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function currency() { return $this->belongsTo(Currency::class); }
    public function transaction() { return $this->belongsTo(MoneyTransaction::class, 'money_transaction_id'); }
    public function repayments() { return $this->hasMany(EmployeeAdvanceRepayment::class, 'employee_salary_advance_id'); }
}
