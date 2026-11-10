<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalesReturnQuarantine extends Model {
    use \App\Models\Concerns\StoresDateOnlyColumns;

 protected $fillable=['sales_credit_note_line_id','production_batch_id',
   'received_warehouse_id','released_warehouse_id','quantity_packages',
   'status','registered_by','decided_by','decided_on','quality_reference','decision_notes'];
 protected $casts=['decided_on'=>'date'];
 public function creditLine(){return $this->belongsTo(SalesCreditNoteLine::class,'sales_credit_note_line_id');}
 public function batch(){return $this->belongsTo(ProductionBatch::class,'production_batch_id');}
}