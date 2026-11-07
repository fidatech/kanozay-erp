<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_profile_id', 'salary_snapshot', 'payable_amount',
        'adjustment_reason', 'payment_transaction_id', 'paid_at',
    ];

    protected $casts = ['salary_snapshot' => 'decimal:4', 'payable_amount' => 'decimal:4', 'paid_at' => 'datetime'];

    public function period() { return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id'); }
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function payment() { return $this->belongsTo(MoneyTransaction::class, 'payment_transaction_id'); }
    public function components() {return $this->hasMany(PayrollLineComponent::class,'payroll_line_id');}
}
