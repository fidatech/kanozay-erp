<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStockCount extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'number', 'submission_token', 'counted_on', 'status', 'notes', 'created_by',
        'approved_by', 'approved_at', 'company_warehouse_id', 'count_scope',
    ];

    protected function casts(): array
    {
        return ['counted_on' => 'date', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(InventoryStockCountLine::class)->orderBy('inventory_material_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(CompanyWarehouse::class, 'company_warehouse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
