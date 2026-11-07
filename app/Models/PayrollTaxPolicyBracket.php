<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PayrollTaxPolicyBracket extends Model {
 protected $fillable=['sort_order','lower_bound','upper_bound','fixed_tax',
   'marginal_percent','excess_above'];
 protected $casts=['lower_bound'=>'decimal:4','upper_bound'=>'decimal:4',
  'fixed_tax'=>'decimal:4','marginal_percent'=>'decimal:4','excess_above'=>'decimal:4'];
 public function policy(){return $this->belongsTo(PayrollTaxPolicy::class,'payroll_tax_policy_id');}
}