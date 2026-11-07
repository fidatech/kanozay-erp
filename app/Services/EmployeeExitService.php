<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\OperationalRegister;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeExitService
{
 /**
  * Called inside the already approved operational-record close transaction.
  * Never erases payroll, attendance, assets, reimbursements or access history.
  */
 public function closeApprovedRecord(OperationalRegister $record,int $actorId):void
 {
  if($record->workflow!=='hr.exits' || $record->status!=='approved')
   throw ValidationException::withMessages(['decision'=>'Only a reviewed employee separation can be closed.']);
  $data=$record->data;
  if(empty($data['asset_clearance']) || empty($data['finance_clearance']))
   throw ValidationException::withMessages(['decision'=>'Document signed finance and asset clearance references before final separation.']);
  if(($data['last_working_on']??'')>today()->toDateString())
   throw ValidationException::withMessages(['decision'=>'Employee separation cannot close before the last working date.']);
  $employee=EmployeeProfile::whereKey($data['employee'])->lockForUpdate()->firstOrFail();
  if(!$employee->is_active)
   throw ValidationException::withMessages(['decision'=>'Employee profile is already inactive. Review prior separation records.']);
  if(DB::table('fixed_assets')->where('custodian_id',$employee->id)->exists() ||
    DB::table('fixed_asset_assignments')->where('employee_profile_id',$employee->id)->whereNull('returned_on')->exists())
   throw ValidationException::withMessages(['decision'=>'Employee holds unreturned company assets. Record actual returns before closure.']);
  if(DB::table('payroll_lines as l')
    ->join('payroll_periods as p','p.id','=','l.payroll_period_id')
    ->where('l.employee_profile_id',$employee->id)->where('p.status','draft')->exists())
   throw ValidationException::withMessages(['decision'=>'Employee appears in draft payroll. Resolve and approve the payroll snapshot before deactivation.']);
  $advances=DB::table('employee_salary_advances')
    ->where('employee_profile_id',$employee->id)->lockForUpdate()->get();
  foreach($advances as $advance){
   $remaining=BigDecimal::of((string)$advance->amount);
   foreach(DB::table('employee_advance_repayments')
     ->where('employee_salary_advance_id',$advance->id)->get() as $repayment)
    $remaining=$remaining->minus((string)$repayment->amount);
   if($remaining->isGreaterThan(0))
    throw ValidationException::withMessages(['decision'=>'Employee has an outstanding salary advance. Collect or formally reconcile it before separation.']);
  }
  $employee->update(['is_active'=>false]);
  ActivityLog::record('hr.employee.exit.closed','employee_profile',$employee->id,
    'Employee offboarding closed from approved separation '.$record->number.'; future salary snapshots will exclude the employee.',
    ['operational_record_id'=>$record->id,'asset_clearance'=>$data['asset_clearance'],
      'finance_clearance'=>$data['finance_clearance'],'actor_id'=>$actorId]);
 }
}
