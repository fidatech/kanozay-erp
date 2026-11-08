<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesDeliveryNote extends Model
{
    protected $fillable=[
        'number','submission_token','sales_invoice_id','delivery_date','status',
        'delivery_address','vehicle_number','driver_name','reference','notes',
        'received_by','delivered_at','created_by','delivered_by','cancelled_by','cancelled_at',
    ];
    protected $casts=[
        'delivery_date'=>'date','delivered_at'=>'datetime','cancelled_at'=>'datetime',
    ];

    public function invoice(){return $this->belongsTo(SalesInvoice::class,'sales_invoice_id');}
    public function lines(){return $this->hasMany(SalesDeliveryNoteLine::class,'sales_delivery_note_id');}
    public function creator(){return $this->belongsTo(User::class,'created_by');}
    public function deliveryProof(){return $this->hasOne(SalesDeliveryTrackingEvent::class,'sales_delivery_note_id')->where('status','delivered')->latestOfMany('id');}
}
