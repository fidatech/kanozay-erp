<?php
namespace App\Services;

use App\Models\{ActivityLog,Currency,LedgerAccount,PayrollLine,PayrollTaxPolicy,PayrollPeriod};
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollTaxPolicyService {
 private function reject(string $field,string $message):never {
  throw ValidationException::withMessages([$field=>$message]);
 }

 public function create(array $data,int $actorId):PayrollTaxPolicy {
  return DB::transaction(function()use($data,$actorId){
   $currency=Currency::whereKey($data['currency_id'])->where('is_active',true)->firstOrFail();
   $ledger=LedgerAccount::whereKey($data['liability_ledger_id'])
    ->where('type','liability')->where('is_group',false)->where('is_active',true)->first();
   if(!$ledger || DB::table('entity_ledger_accounts')->where('ledger_account_id',$ledger->id)->exists())
    $this->reject('liability_ledger_id','Select an active, independent withholding liability ledger.');
   $effective=\Carbon\CarbonImmutable::parse($data['effective_from']);
   if($effective->day!==1)$this->reject('effective_from','A tax policy becomes effective on the first day of its month.');
   $brackets=$data['brackets'];
   if(count($brackets)<1||count($brackets)>20)
    $this->reject('brackets','Enter between 1 and 20 salary brackets.');
   usort($brackets,fn($a,$b)=>BigDecimal::of((string)$a['lower_bound'])
    ->compareTo((string)$b['lower_bound']));
   $expected=BigDecimal::zero();$rows=[];
   foreach($brackets as $i=>$bracket){
    $lower=BigDecimal::of((string)$bracket['lower_bound'])->toScale(4);
    $upper=isset($bracket['upper_bound'])&&$bracket['upper_bound']!==''
      ?BigDecimal::of((string)$bracket['upper_bound'])->toScale(4):null;
    $fixed=BigDecimal::of((string)$bracket['fixed_tax'])->toScale(4);
    $rate=BigDecimal::of((string)$bracket['marginal_percent'])->toScale(4);
    if(!$lower->isEqualTo($expected))
     $this->reject('brackets','The first threshold must be zero and brackets must be continuous without overlaps or gaps.');
    if($fixed->isNegative() || $rate->isNegative() || $rate->isGreaterThan('100'))
     $this->reject('brackets','Fixed withholding must be nonnegative and marginal percentage must be from 0 to 100.');
    if($upper!==null && !$upper->isGreaterThan($lower))
     $this->reject('brackets','A bracket upper boundary must exceed its lower boundary.');
    if($upper===null && $i!==count($brackets)-1)
     $this->reject('brackets','Only the final bracket may be open-ended.');
    $rows[]=['sort_order'=>$i+1,'lower_bound'=>(string)$lower,
     'upper_bound'=>$upper===null?null:(string)$upper,
     'fixed_tax'=>(string)$fixed,'marginal_percent'=>(string)$rate,
     'excess_above'=>(string)$lower];
    if($upper!==null)$expected=$upper;
   }
   if(end($rows)['upper_bound']!==null)
    $this->reject('brackets','The final bracket must have no upper limit.');
   $policy=PayrollTaxPolicy::create([
    'code'=>strtoupper(trim($data['code'])),'name'=>trim($data['name']),
    'currency_id'=>$currency->id,'effective_from'=>$effective->toDateString(),
    'basis'=>$data['basis'],'liability_ledger_id'=>$ledger->id,
    'notes'=>$data['notes'],'status'=>'draft','created_by'=>$actorId,
   ]);
   $policy->brackets()->createMany($rows);
   ActivityLog::record('payroll.tax_policy.created','payroll_tax_policy',$policy->id,
    'Draft employee tax policy created; cannot affect salaries before independent finance approval.');
   return $policy->load('brackets');
  });
 }

 public function approve(PayrollTaxPolicy $policy,string $reference,int $actorId):PayrollTaxPolicy {
  return DB::transaction(function()use($policy,$reference,$actorId){
   $row=PayrollTaxPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
   if($row->status!=='draft')$this->reject('policy','Only draft tax policies can be approved.');
   if($row->created_by===$actorId)$this->reject('policy','A separate finance reviewer must approve the tax brackets.');
   if(PayrollTaxPolicy::where('currency_id',$row->currency_id)
    ->whereDate('effective_from',$row->effective_from->toDateString())
    ->where('status','approved')->where('id','!=',$row->id)->exists())
    $this->reject('effective_from','Another approved policy already begins on this currency/date.');
   $ledger=LedgerAccount::whereKey($row->liability_ledger_id)->where('type','liability')
    ->where('is_active',true)->where('is_group',false)->first();
   if(!$ledger)$this->reject('liability_ledger_id','Tax liability ledger must remain active for approval.');
   $row->update(['status'=>'approved','approved_by'=>$actorId,
    'approval_reference'=>$reference,'approved_at'=>now()]);
   ActivityLog::record('payroll.tax_policy.approved','payroll_tax_policy',$row->id,
    'Tax policy approved by a different reviewer: '.$reference);
   return $row;
  });
 }

 public function calculate(PayrollTaxPolicy $policy,string $gross):string {
  $base=BigDecimal::of($gross)->toScale(4);
  if($base->isNegative())$this->reject('taxable_base','Salary amount must be nonnegative.');
  foreach($policy->brackets as $bracket){
   $min=BigDecimal::of((string)$bracket->lower_bound);
   $max=$bracket->upper_bound===null?null:BigDecimal::of((string)$bracket->upper_bound);
   if($base->isLessThan($min)||($max!==null&&!$base->isLessThan($max)))continue;
   $excess=$base->minus((string)$bracket->excess_above);
   if($excess->isNegative())$excess=BigDecimal::zero();
   $tax=BigDecimal::of((string)$bracket->fixed_tax)->plus(
    $excess->multipliedBy((string)$bracket->marginal_percent)
     ->dividedBy('100',8,RoundingMode::HalfUp)
   );
   return (string)$tax->toScale(4,RoundingMode::HalfUp);
  }
  $this->reject('policy','No tax bracket covers this salary. Review the policy before using it.');
 }

 public function apply(PayrollTaxPolicy $policy,PayrollPeriod $period,int $actorId):array {
  return DB::transaction(function()use($policy,$period,$actorId){
   $period=PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
   $policy=PayrollTaxPolicy::with('brackets')->whereKey($policy->id)->lockForUpdate()->firstOrFail();
   if($period->status!=='draft')$this->reject('period','Only draft payroll can receive tax withholdings.');
   if($policy->status!=='approved'||$policy->currency_id!==$period->currency_id ||
       $policy->effective_from->greaterThan($period->period_start))
    $this->reject('policy','Select an approved policy for this payroll currency and month.');
   if(PayrollTaxPolicy::where('currency_id',$period->currency_id)->where('status','approved')
     ->whereDate('effective_from','<=',$period->period_start->toDateString())
     ->whereDate('effective_from','>',$policy->effective_from->toDateString())->exists())
    $this->reject('policy','A newer approved tax policy applies to this payroll period.');
   $lines=$period->lines()->orderBy('id')->get();
   if($lines->isEmpty())$this->reject('period','Payroll has no employees.');
   $count=0;$sum=BigDecimal::zero();
   foreach($lines as $line){
    $prev=DB::table('payroll_tax_applications')->where('payroll_line_id',$line->id)->first();
    if($prev){
     if((int)$prev->payroll_tax_policy_id!==$policy->id)
      $this->reject('policy','Tax was already calculated using another approved policy for an employee.');
     continue;
    }
    $base=BigDecimal::of((string)$line->salary_snapshot);
    if($policy->basis==='salary_plus_allowances'){
     foreach($line->components()->where('kind','allowance')->get() as $allowance)
      $base=$base->plus((string)$allowance->amount);
    }
    $base=$base->toScale(4);
    $tax=BigDecimal::of($this->calculate($policy,(string)$base));
    $componentId=null;
    if($tax->isPositive()){
     $component=app(PayrollComponentService::class)->add($period,$line,[
      'submission_token'=>(string)Str::uuid(),'kind'=>'deduction',
      'label'=>'Approved tax '.$policy->code,'amount'=>(string)$tax,
      'ledger_account_id'=>$policy->liability_ledger_id,
      'approval_reference'=>$policy->approval_reference,
      'reason'=>'Withheld using approved '.$policy->basis.' bracket policy '.$policy->code.
        ' effective '.$policy->effective_from->toDateString(),
     ],$actorId);
     $componentId=$component->id;
    }
    DB::table('payroll_tax_applications')->insert([
     'payroll_line_id'=>$line->id,'payroll_line_component_id'=>$componentId,
     'payroll_tax_policy_id'=>$policy->id,'taxable_base'=>(string)$base,
     'withheld'=>(string)$tax->toScale(4),'applied_by'=>$actorId,
     'created_at'=>now(),'updated_at'=>now(),
    ]);
    $sum=$sum->plus($tax);$count++;
   }
   ActivityLog::record('payroll.tax_policy.applied','payroll_period',$period->id,
    'Reviewed approved employee withholding brackets applied to payroll.',
    ['policy_id'=>$policy->id,'lines_new'=>$count,'withheld'=>(string)$sum]);
   return ['lines'=>$count,'total'=>(string)$sum->toScale(4)];
  });
 }
}