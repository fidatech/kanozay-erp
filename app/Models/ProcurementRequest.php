<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementRequest extends Model
{
    protected $fillable=[
        'number','submission_token','status','requested_on','required_on','notes',
        'decision_note','created_by','approved_by','decided_at','purchase_order_id',
    ];
    protected $casts=['requested_on'=>'date','required_on'=>'date','decided_at'=>'datetime'];
    public function lines(){return $this->hasMany(ProcurementRequestLine::class);}
    public function creator(){return $this->belongsTo(User::class,'created_by');}
    public function approver(){return $this->belongsTo(User::class,'approved_by');}
    public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
}
