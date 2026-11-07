<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesDeliveryTrackingEvent extends Model {
 protected $fillable=[
  'submission_token','sales_delivery_note_id','status','occurred_at','location',
  'proof_reference','receiver_name','notes','created_by',
 ];
 protected $casts=['occurred_at'=>'datetime'];
 protected static function booted():void {
  static::updating(fn()=>throw new LogicException('Delivery tracking history is immutable.'));
  static::deleting(fn()=>throw new LogicException('Delivery tracking history cannot be deleted.'));
 }
 public function note(){return $this->belongsTo(SalesDeliveryNote::class,'sales_delivery_note_id');}
 public function actor(){return $this->belongsTo(User::class,'created_by');}
}