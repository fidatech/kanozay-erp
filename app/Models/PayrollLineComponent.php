<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class PayrollLineComponent extends Model {
 protected $fillable=['payroll_line_id','submission_token','kind','label','amount',
  'ledger_account_id','approval_reference','reason','created_by'];
 protected $casts=['amount'=>'decimal:4'];
 protected static function booted():void {
  static::updating(fn()=>throw new LogicException('Reviewed payroll components are immutable.'));
  static::deleting(fn()=>throw new LogicException('Reviewed payroll components cannot be deleted.'));
 }
 public function line(){return $this->belongsTo(PayrollLine::class,'payroll_line_id');}
 public function ledger(){return $this->belongsTo(LedgerAccount::class,'ledger_account_id');}
}
