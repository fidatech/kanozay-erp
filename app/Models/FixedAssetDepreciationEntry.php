<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FixedAssetDepreciationEntry extends Model
{
    protected $fillable=['fixed_asset_id','policy_id','period_month','amount','journal_entry_id','posted_by'];
    protected $casts=['period_month'=>'date','amount'=>'decimal:4'];
    protected static function booted():void {
        static::updating(fn()=>throw new LogicException('Posted depreciation entries are immutable.'));
        static::deleting(fn()=>throw new LogicException('Posted depreciation entries cannot be deleted.'));
    }
    public function asset(){return $this->belongsTo(FixedAsset::class,'fixed_asset_id');}
    public function journal(){return $this->belongsTo(JournalEntry::class,'journal_entry_id');}
}
