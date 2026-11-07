<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    protected $fillable = [
        'asset_tag', 'name', 'fixed_asset_category_id', 'acquired_on', 'acquisition_cost',
        'currency_id', 'source_reference', 'status', 'custodian_id', 'notes', 'created_by', 'company_warehouse_id',
    ];
    protected $casts = ['acquired_on' => 'date', 'acquisition_cost' => 'decimal:4'];
    public function category() { return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id'); }
    public function currency() { return $this->belongsTo(Currency::class); }
    public function custodian() { return $this->belongsTo(EmployeeProfile::class, 'custodian_id'); }
    public function assignments() { return $this->hasMany(FixedAssetAssignment::class); }
}
