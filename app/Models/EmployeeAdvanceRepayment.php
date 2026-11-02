<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAdvanceRepayment extends Model
{
    protected $fillable = ['employee_salary_advance_id', 'amount', 'received_on', 'money_transaction_id', 'submission_token', 'created_by'];
    protected $casts = ['amount' => 'decimal:4', 'received_on' => 'date'];
    public function advance() { return $this->belongsTo(EmployeeSalaryAdvance::class, 'employee_salary_advance_id'); }
    public function transaction() { return $this->belongsTo(MoneyTransaction::class, 'money_transaction_id'); }
}
