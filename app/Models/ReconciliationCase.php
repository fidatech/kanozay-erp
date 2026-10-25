<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationCase extends Model {
 protected $fillable=['type','trade_party_id','currency_id','status','assigned_to','notes','updated_by','resolved_at'];
 protected function casts():array{return ['resolved_at'=>'datetime'];}
 public function assignee(){return $this->belongsTo(User::class,'assigned_to');}
}
