<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PayrollTaxPolicy extends Model {
    use \App\Models\Concerns\StoresDateOnlyColumns;

 protected $fillable=['code','name','currency_id','effective_from','basis','liability_ledger_id',
   'status','approval_reference','notes','created_by','approved_by','approved_at'];
 protected $casts=['effective_from'=>'date','approved_at'=>'datetime'];
 public function brackets(){return $this->hasMany(PayrollTaxPolicyBracket::class)->orderBy('sort_order');}
 public function currency(){return $this->belongsTo(Currency::class);}
 public function ledger(){return $this->belongsTo(LedgerAccount::class,'liability_ledger_id');}
}