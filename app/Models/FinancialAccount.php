<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FinancialAccount extends Model {
 protected $fillable=['type','name','currency_id','institution','account_number','contact','notes','is_active','created_by'];
 protected $casts=['is_active'=>'boolean'];
 public function currency():BelongsTo{return $this->belongsTo(Currency::class);}
}
