<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetAssignment extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'employee_profile_id', 'assigned_on', 'returned_on',
        'assigned_by', 'returned_by', 'notes',
    ];
    protected $casts = ['assigned_on' => 'date', 'returned_on' => 'date'];
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
}
