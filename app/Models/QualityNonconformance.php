<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityNonconformance extends Model
{
    use \App\Models\Concerns\StoresDateOnlyColumns;

    protected $table='quality_nonconformances';
    protected $guarded=['*'];
    protected $casts=['opened_on'=>'date','due_on'=>'date','closed_at'=>'datetime'];
    public function sample(){return $this->belongsTo(QualitySample::class,'quality_sample_id');}
}
