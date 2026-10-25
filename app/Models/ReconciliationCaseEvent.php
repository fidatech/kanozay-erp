<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationCaseEvent extends Model {
 protected $fillable=['reconciliation_case_id','actor_id','action','old_status','new_status','notes'];
 public static function signature(self $event,?string $previous):string {
  $payload=json_encode([$event->reconciliation_case_id,$event->actor_id,$event->action,$event->old_status,$event->new_status,$event->notes,$previous],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  return hash_hmac('sha256',$payload,config('app.key'));
 }
 protected static function booted():void {
  static::creating(function(self $event){
   if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) throw new \LogicException('Reconciliation audit events require an active database transaction.');
   \Illuminate\Support\Facades\DB::table('reconciliation_cases')->where('id',$event->reconciliation_case_id)->lockForUpdate()->first();
   $previous=self::where('reconciliation_case_id',$event->reconciliation_case_id)->orderByDesc('id')->first();
   if($previous && !$previous->event_hash) throw new \LogicException('Unsigned historical audit events require review before appending new events.');
   $event->previous_hash=$previous?->event_hash;
   $event->event_hash=self::signature($event,$event->previous_hash);
  });
  static::updating(function(){throw new \LogicException('Reconciliation audit events are immutable.');});
  static::deleting(function(){throw new \LogicException('Reconciliation audit events cannot be deleted.');});
 }
 public function actor(){return $this->belongsTo(User::class,'actor_id');}
}