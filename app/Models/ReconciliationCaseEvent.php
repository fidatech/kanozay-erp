<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationCaseEvent extends Model {
 protected $fillable=['reconciliation_case_id','actor_id','action','old_status','new_status','notes'];
 protected static function booted():void {
  static::updating(function(){throw new \LogicException('Reconciliation audit events are immutable.');});
  static::deleting(function(){throw new \LogicException('Reconciliation audit events cannot be deleted.');});
 }
 public function actor(){return $this->belongsTo(User::class,'actor_id');}
}