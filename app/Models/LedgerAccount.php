<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class LedgerAccount extends Model {
 protected $fillable=['code','name','type','parent_id','is_group','is_active','description','created_by'];
 protected $casts=['is_group'=>'boolean','is_active'=>'boolean'];
 public function parent():BelongsTo{return $this->belongsTo(self::class,'parent_id');}
 public function children():HasMany{return $this->hasMany(self::class,'parent_id');}
}
