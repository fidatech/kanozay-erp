<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalRegister extends Model
{
 protected $fillable=[
  'workflow','number','submission_token','data','status','review_note',
  'created_by','reviewed_by','reviewed_at','closed_by','closed_at',
 ];
 protected $casts=['data'=>'array','reviewed_at'=>'datetime','closed_at'=>'datetime'];
 public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by');}
 public function reviewer():BelongsTo{return $this->belongsTo(User::class,'reviewed_by');}
 public function events():HasMany{return $this->hasMany(OperationalRegisterEvent::class);}
}
