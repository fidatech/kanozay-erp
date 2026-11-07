<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesDeliveryNoteLine extends Model
{
    protected $fillable=['sales_invoice_line_id','quantity_packages'];
    protected static function booted():void
    {
        static::updating(fn()=>throw new LogicException('Issued delivery note lines cannot be edited.'));
        static::deleting(fn()=>throw new LogicException('Issued delivery note lines cannot be deleted.'));
    }
    public function note(){return $this->belongsTo(SalesDeliveryNote::class,'sales_delivery_note_id');}
    public function invoiceLine(){return $this->belongsTo(SalesInvoiceLine::class,'sales_invoice_line_id');}
}
