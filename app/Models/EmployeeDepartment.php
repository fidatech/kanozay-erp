<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDepartment extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'created_by'];
    protected $casts = ['is_active' => 'boolean'];
    public function employees() { return $this->hasMany(EmployeeProfile::class, 'department_id'); }
}
