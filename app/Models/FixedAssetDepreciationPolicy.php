<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetDepreciationPolicy extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable = [
        'fixed_asset_id','useful_life_months','salvage_value','start_month',
        'expense_ledger_id','accumulated_ledger_id','approved_by',
    ];
    protected $casts = ['salvage_value'=>'decimal:4','start_month'=>'date'];
    public function asset(){return $this->belongsTo(FixedAsset::class,'fixed_asset_id');}
    public function entries(){return $this->hasMany(FixedAssetDepreciationEntry::class,'policy_id');}
    public function expenseLedger(){return $this->belongsTo(LedgerAccount::class,'expense_ledger_id');}
    public function accumulatedLedger(){return $this->belongsTo(LedgerAccount::class,'accumulated_ledger_id');}
}
