<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeProfile extends Model
{
    protected $fillable = [
        'employee_number', 'full_name', 'father_name', 'gender', 'position', 'department_id',
        'phone', 'national_id', 'taxpayer_id', 'monthly_salary', 'salary_currency_id', 'is_active', 'hired_on', 'separated_on', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'father_name' => 'encrypted',
            'phone' => 'encrypted',
            'national_id' => 'encrypted',
            'taxpayer_id' => 'encrypted',
            'monthly_salary' => 'decimal:4',
            'is_active' => 'boolean',
            'hired_on' => 'date',
            'separated_on' => 'date',
        ];
    }

    public function salaryCurrency()
    {
        return $this->belongsTo(Currency::class, 'salary_currency_id');
    }

    public function department() { return $this->belongsTo(EmployeeDepartment::class, 'department_id'); }
}
