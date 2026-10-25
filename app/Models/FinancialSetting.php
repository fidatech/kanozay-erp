<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinancialSetting extends Model { protected $fillable=['base_currency_id','fiscal_year_start','allow_negative_stock','require_payment_approval','require_purchase_approval','purchase_mode','updated_by']; protected $casts=['allow_negative_stock'=>'boolean','require_payment_approval'=>'boolean','require_purchase_approval'=>'boolean']; }
