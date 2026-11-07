<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalRegisterEvent extends Model
{
 protected $fillable=['operational_register_id','action','snapshot','note','user_id'];
 protected $casts=['snapshot'=>'array'];
 protected static function booted():void
 {
  static::updating(fn()=>throw new \LogicException('Audited workflow events cannot be updated.'));
  static::deleting(fn()=>throw new \LogicException('Audited workflow events cannot be deleted.'));
 }
 public function actor(){return $this->belongsTo(User::class,'user_id');}
}
