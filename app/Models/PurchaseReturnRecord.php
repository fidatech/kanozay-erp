<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PurchaseReturnRecord extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $fillable=[
        'number','submission_token','goods_receipt_id','purchase_order_id','currency_id',
        'company_warehouse_id','returned_on','supplier_reference','status','financial_status',
        'reason','created_by',
    ];
    protected $casts=['returned_on'=>'date'];
    protected static function booted():void {
        static::updating(fn()=>throw new LogicException('Posted purchase returns are immutable.'));
        static::deleting(fn()=>throw new LogicException('Posted purchase returns cannot be deleted.'));
    }
    public function receipt(){return $this->belongsTo(GoodsReceipt::class,'goods_receipt_id');}
    public function order(){return $this->belongsTo(PurchaseOrder::class,'purchase_order_id');}
    public function currency(){return $this->belongsTo(Currency::class,'currency_id');}
    public function lines(){return $this->hasMany(PurchaseReturnRecordLine::class,'purchase_return_record_id');}
}

