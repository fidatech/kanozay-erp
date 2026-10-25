<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationCase extends Model {
 protected $fillable=['type','trade_party_id','currency_id','status','assigned_to','notes','updated_by','resolved_at','approved_exposure','approved_ledger','approved_by','approved_at'];
 protected function casts():array{return ['resolved_at'=>'datetime','approved_at'=>'datetime'];}
 public function events(){return $this->hasMany(ReconciliationCaseEvent::class)->latest();}
 public function assignee(){return $this->belongsTo(User::class,'assigned_to');}
}
