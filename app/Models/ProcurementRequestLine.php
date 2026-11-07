<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProcurementRequestLine extends Model
{
    protected $fillable=['inventory_material_id','quantity','unit_snapshot','description'];
    protected $casts=['quantity'=>'decimal:6'];
    protected static function booted():void
    {
        static::updating(fn()=>throw new LogicException('Submitted requisitions cannot have their lines edited.'));
        static::deleting(fn()=>throw new LogicException('Submitted requisition lines cannot be deleted.'));
    }
    public function material(){return $this->belongsTo(InventoryMaterial::class,'inventory_material_id');}
    public function request(){return $this->belongsTo(ProcurementRequest::class,'procurement_request_id');}
}
