<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveRequest extends Model
{
    protected $fillable = [
        'employee_profile_id', 'starts_on', 'ends_on', 'reason', 'status',
        'decision_note', 'created_by', 'decided_by', 'decided_at',
    ];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'decided_at' => 'datetime'];
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
}
