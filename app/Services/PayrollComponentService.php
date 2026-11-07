<?php
namespace App\Services;
use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use App\Models\PayrollLine;
use App\Models\PayrollLineComponent;
use App\Models\PayrollPeriod;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollComponentService {
 public function add(PayrollPeriod $period,PayrollLine $line,array $data,int $actor):PayrollLineComponent {
  return DB::transaction(function()use($period,$line,$data,$actor){
   $existing=PayrollLineComponent::where('submission_token',$data['submission_token'])->first();
   if($existing){
    if($existing->payroll_line_id===$line->id && $existing->kind===$data['kind'] &&
       $existing->label===$data['label'] &&
       BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']))return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Component token is already used.']);
   }
   $p=PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
   $item=PayrollLine::where('payroll_period_id',$p->id)->whereKey($line->id)->lockForUpdate()->firstOrFail();
   if($p->status!=='draft'||$item->payment_transaction_id)
    throw ValidationException::withMessages(['payroll_line_id'=>'Only unpaid draft payroll components may be added.']);
   $ledger=LedgerAccount::whereKey($data['ledger_account_id'])->where('is_active',true)
    ->where('is_group',false)->first();
   $type=$data['kind']==='allowance'?'expense':'liability';
   if(!$ledger||$ledger->type!==$type||
     DB::table('entity_ledger_accounts')->where('ledger_account_id',$ledger->id)->exists())
    throw ValidationException::withMessages(['ledger_account_id'=>'Use an active non-group, unmapped '.$type.' ledger for this component.']);

   $current=BigDecimal::of((string)$item->salary_snapshot);
   foreach($item->components as $component){
    $current=$component->kind==='allowance'
      ?$current->plus((string)$component->amount):$current->minus((string)$component->amount);
   }
   if(!$current->isEqualTo((string)$item->payable_amount))
    throw ValidationException::withMessages(['payroll_line_id'=>'This line has a direct manual adjustment. Revert to the salary/component total before adding itemized components.']);
   $amount=BigDecimal::of((string)$data['amount'])->toScale(4);
   $next=$data['kind']==='allowance'?$current->plus($amount):$current->minus($amount);
   if($next->isNegative())
    throw ValidationException::withMessages(['amount'=>'Payroll deductions cannot make net salary negative.']);
   $component=PayrollLineComponent::create([
    'payroll_line_id'=>$item->id,'submission_token'=>$data['submission_token'],
    'kind'=>$data['kind'],'label'=>$data['label'],'amount'=>(string)$amount,
    'ledger_account_id'=>$ledger->id,'approval_reference'=>$data['approval_reference'],
    'reason'=>$data['reason'],'created_by'=>$actor,
   ]);
   $item->update(['payable_amount'=>(string)$next->toScale(4),
    'adjustment_reason'=>'Itemized and approved payroll components']);
   ActivityLog::record('payroll.component.added','payroll_line_component',$component->id,
    'Reviewed payroll '.$data['kind'].' recorded; liability posting occurs on period approval.');
   return $component;
  });
 }
}
