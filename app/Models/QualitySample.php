<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualitySample extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $table='quality_samples';
    protected $guarded=['*'];
    protected $casts=['collected_on'=>'date','reviewed_at'=>'datetime'];

    public function batch(){return $this->belongsTo(ProductionBatch::class,'production_batch_id');}
    public function receiptLine(){return $this->belongsTo(GoodsReceiptLine::class,'goods_receipt_line_id');}
}
