<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendance extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $table = 'employee_attendance';
    protected $fillable = [
        'employee_profile_id', 'attendance_date', 'status', 'notes', 'correction_reason',
        'recorded_by', 'corrected_by',
    ];
    protected $casts = ['attendance_date' => 'date'];
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
}
