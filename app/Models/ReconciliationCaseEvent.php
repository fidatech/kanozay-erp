<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationCaseEvent extends Model {
 protected $fillable=['reconciliation_case_id','actor_id','action','old_status','new_status','notes'];
 public function actor(){return $this->belongsTo(User::class,'actor_id');}
}