<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remit only independently approved, genuinely accrued original-currency
 * employee withholding. Tax rates and authority remain client-supplied.
 */
final class PayrollTaxRemittanceService
{
    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['tax_remittance'=>$message]);
    }

    private function source(int $periodId,int $policyId):array
    {
        $period=DB::table('payroll_periods')->where('id',$periodId)->first();
        $policy=DB::table('payroll_tax_policies')->where('id',$policyId)->first();
        if(!$period || !$policy || !in_array($period->status,['approved','paid'],true) ||
            $policy->status!=='approved' || !$policy->approved_by ||
            (int)$policy->currency_id!==(int)$period->currency_id ||
            $policy->effective_from>$period->period_start)
            $this->reject('A final independently approved source payroll period and tax policy must share one authentic currency.');
        $entry=DB::table('journal_entries')->where('id',$period->approval_journal_id)->first();
        $end=\Carbon\CarbonImmutable::parse($period->period_start)->endOfMonth()->toDateString();
        if(!$entry || $entry->status!=='posted' || $entry->reversal_of_id ||
            (string)$entry->entry_date!==$end ||
            (int)$entry->currency_id!==(int)$period->currency_id ||
            DB::table('journal_entries')->where('reversal_of_id',$entry->id)->exists())
            $this->reject('The original salary expense/withholding liability journal is missing, reversed, wrong currency or not dated at genuine month end.');
        $liability=LedgerAccount::whereKey($policy->liability_ledger_id)
            ->where('type','liability')->where('is_active',true)
            ->where('is_group',false)->first();
        if(!$liability || DB::table('entity_ledger_accounts')
            ->where('ledger_account_id',$liability->id)->exists())
            $this->reject('Approved withholding liability must be an active, unmapped ledger.');
        $applications=DB::table('payroll_tax_applications as tax')
            ->join('payroll_lines as line','line.id','=','tax.payroll_line_id')
            ->where('line.payroll_period_id',$periodId)
            ->where('tax.payroll_tax_policy_id',$policyId)
            ->select('tax.*','line.salary_snapshot','line.payable_amount')
            ->orderBy('tax.id')->get();
        $lineCount=DB::table('payroll_lines')->where('payroll_period_id',$periodId)->count();
        if(!$lineCount || $applications->count()!==$lineCount)
            $this->reject('Every real payroll line must be covered by the same applied tax policy; incomplete original applications cannot fund a remittance.');
        $total=BigDecimal::zero();$proof=[];
        foreach($applications as $application){
            $amount=BigDecimal::of((string)$application->withheld);
            if($amount->isNegative())$this->reject('Employee withholding cannot be negative.');
            $component=$application->payroll_line_component_id
                ?DB::table('payroll_line_components')
                    ->where('id',$application->payroll_line_component_id)->first():null;
            if(($amount->isPositive() &&
                (!$component || $component->kind!=='deduction' ||
                (int)$component->payroll_line_id!==(int)$application->payroll_line_id ||
                (int)$component->ledger_account_id!==(int)$liability->id ||
                !BigDecimal::of((string)$component->amount)->isEqualTo($amount))) ||
                ($amount->isZero() && $component))
                $this->reject('The approved employee source withholding, linked payroll component and liability no longer agree.');
            $total=$total->plus($amount);
            $proof[]=[
                $application->id,$application->payroll_line_id,
                $application->payroll_line_component_id,
                $application->salary_snapshot,$application->payable_amount,
                $application->taxable_base,$application->withheld,
                $component?->amount,$component?->ledger_account_id,
            ];
        }
        if(!$total->isPositive())$this->reject('No genuine positive withheld employee liability is available for government remittance.');
        $credit=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$entry->id)
            ->where('ledger_account_id',$liability->id)->get() as $line)
            $credit=$credit->plus((string)$line->credit)->minus((string)$line->debit);
        if($credit->isLessThan($total))
            $this->reject('Original payroll accrual lacks enough posted source-currency tax payable credit for the signed employee deductions.');
        $brackets=DB::table('payroll_tax_policy_brackets')
            ->where('payroll_tax_policy_id',$policyId)->orderBy('sort_order')
            ->get(['sort_order','lower_bound','upper_bound','fixed_tax',
                'marginal_percent','excess_above'])->toArray();
        $fingerprint=hash('sha256',json_encode([
            'payroll'=>[
                $period->id,$period->period_start,$period->currency_id,
                $period->approval_journal_id,$period->created_by,$period->approved_by,
            ],
            'policy'=>[
                $policy->id,$policy->code,$policy->effective_from,$policy->basis,
                $policy->liability_ledger_id,$policy->approval_reference,
                $policy->approved_by,$brackets,
            ],
            'accrual'=>[
                $entry->id,$entry->number,$entry->entry_date,$entry->status,
                $entry->currency_id,(string)$credit,
            ],
            'employees'=>$proof,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return compact('period','policy','entry','liability','total','fingerprint','end');
    }

    private function unchanged(object $proposal,array $source):void
    {
        if((int)$proposal->payroll_period_id!==(int)$source['period']->id ||
            (int)$proposal->payroll_tax_policy_id!==(int)$source['policy']->id ||
            (int)$proposal->currency_id!==(int)$source['period']->currency_id ||
            (int)$proposal->liability_ledger_id!==(int)$source['liability']->id ||
            $proposal->source_fingerprint!==$source['fingerprint'] ||
            !BigDecimal::of((string)$proposal->source_total_withheld)
                ->isEqualTo($source['total']))
            $this->reject('Signed payroll tax source documents changed after proposal. Reconcile original applications and the accrual before approval/payment.');
    }

    private function available(object $proposal,array $source):void
    {
        $reserved=BigDecimal::zero();
        foreach(DB::table('payroll_tax_remittances')
            ->where('payroll_period_id',$source['period']->id)
            ->where('payroll_tax_policy_id',$source['policy']->id)
            ->whereIn('status',['submitted','approved','posted'])
            ->when($proposal->id??null,fn($q,$id)=>$q->where('id','!=',$id))
            ->get(['amount']) as $existing)
            $reserved=$reserved->plus((string)$existing->amount);
        $amount=BigDecimal::of((string)$proposal->amount);
        if(!$amount->isPositive() ||
            $amount->plus($reserved)->isGreaterThan($source['total']))
            $this->reject('The sum of outstanding and previously posted tax remittances exceeds the approved original employee withholding. Partial payments must not duplicate a liability.');
    }

    private function paymentAccount(object $proposal,array $source):array
    {
        $account=FinancialAccount::whereKey($proposal->financial_account_id)
            ->where('is_active',true)->first();
        if(!$account || !in_array($account->type,['bank','cashbox'],true) ||
            (int)$account->currency_id!==(int)$source['period']->currency_id)
            $this->reject('Choose an active bank/cash account in the exact original tax/employee salary currency.');
        $mapping=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>$account->type,'entity_id'=>$account->id,
            'currency_id'=>$account->currency_id,'purpose'=>'cash',
        ])->first();
        $cash=$mapping?LedgerAccount::whereKey($mapping->ledger_account_id)
            ->where('type','asset')->where('is_active',true)
            ->where('is_group',false)->first():null;
        if(!$cash || (int)$cash->id===(int)$source['liability']->id)
            $this->reject('Payment account lacks a valid source-currency bank/cash asset ledger distinct from the tax liability.');
        return compact('account','cash');
    }

    public function submit(array $data,int $actorId):object
    {
        return DB::transaction(function()use($data,$actorId){
            $period=DB::table('payroll_periods')
                ->where('id',$data['payroll_period_id'])->lockForUpdate()->first();
            abort_unless($period,404);
            $source=$this->source($period->id,(int)$data['payroll_tax_policy_id']);
            $existing=DB::table('payroll_tax_remittances')
                ->where('submission_token',$data['submission_token'])->first();
            if($existing){
                if((int)$existing->payroll_period_id===(int)$period->id &&
                    (int)$existing->payroll_tax_policy_id===(int)$source['policy']->id &&
                    BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']))
                    return $existing;
                $this->reject('This remittance token already belongs to a different signed source record.');
            }
            if(in_array($actorId,[
                (int)$period->approved_by,(int)$source['policy']->approved_by],true))
                $this->reject('The original payroll/tax-policy approver cannot propose the final tax payment.');
            if($data['remit_on']<$source['end'] ||
                $data['remit_on']>today()->toDateString())
                $this->reject('A remittance cannot precede its original approved salary accrual or be dated in the future.');
            $proposal=(object)[
                'amount'=>$data['amount'],
                'financial_account_id'=>$data['financial_account_id'],
            ];
            $this->available($proposal,$source);
            $this->paymentAccount($proposal,$source);
            $id=DB::table('payroll_tax_remittances')->insertGetId([
                'submission_token'=>$data['submission_token'],
                'payroll_period_id'=>$period->id,
                'payroll_tax_policy_id'=>$source['policy']->id,
                'currency_id'=>$period->currency_id,
                'liability_ledger_id'=>$source['liability']->id,
                'financial_account_id'=>$data['financial_account_id'],
                'amount'=>(string)BigDecimal::of((string)$data['amount'])->toScale(4),
                'remit_on'=>$data['remit_on'],
                'source_fingerprint'=>$source['fingerprint'],
                'source_total_withheld'=>(string)$source['total']->toScale(4),
                'evidence_reference'=>trim($data['evidence_reference']),
                'source_notes'=>trim($data['source_notes']),
                'status'=>'submitted','requested_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('payroll.tax_remittance.submitted',
                'payroll_tax_remittance',$id,
                'Original-currency withholding remittance proposed. No source salary, GL or bank transaction changed.');
            return DB::table('payroll_tax_remittances')->where('id',$id)->first();
        });
    }

    public function review(int $id,array $data,int $actorId):object
    {
        return DB::transaction(function()use($id,$data,$actorId){
            $original=DB::table('payroll_tax_remittances')->where('id',$id)->first();
            abort_unless($original,404);
            DB::table('payroll_periods')->where('id',$original->payroll_period_id)
                ->lockForUpdate()->firstOrFail();
            $p=DB::table('payroll_tax_remittances')->where('id',$id)->lockForUpdate()->first();
            if($p->status!=='submitted')$this->reject('Only an unreviewed payroll tax payment can receive approval.');
            $source=$this->source($p->payroll_period_id,$p->payroll_tax_policy_id);
            if(in_array($actorId,[
                (int)$p->requested_by,(int)$source['period']->created_by,
                (int)$source['period']->approved_by,
                (int)$source['policy']->approved_by,
            ],true))
                $this->reject('An independent finance reviewer who is not an earlier payroll/policy source actor must approve this payment.');
            $this->unchanged($p,$source);
            $this->available($p,$source);
            $this->paymentAccount($p,$source);
            DB::table('payroll_tax_remittances')->where('id',$id)->update([
                'status'=>$data['decision']==='approve'?'approved':'rejected',
                'review_reference'=>trim($data['review_reference']),
                'review_notes'=>trim($data['review_notes']),
                'reviewed_by'=>$actorId,'reviewed_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('payroll.tax_remittance.reviewed',
                'payroll_tax_remittance',$id,
                'Independent original-currency payroll tax remittance '.($data['decision']==='approve'?'approved':'rejected').'.');
            return DB::table('payroll_tax_remittances')->where('id',$id)->first();
        });
    }

    public function post(int $id,int $actorId):object
    {
        return DB::transaction(function()use($id,$actorId){
            $close=app(FinancePeriodClosingService::class);
            $close->holdPostingGate();
            $original=DB::table('payroll_tax_remittances')->where('id',$id)->first();
            abort_unless($original,404);
            DB::table('payroll_periods')->where('id',$original->payroll_period_id)
                ->lockForUpdate()->firstOrFail();
            $p=DB::table('payroll_tax_remittances')->where('id',$id)->lockForUpdate()->first();
            if($p->status!=='approved' || $p->journal_entry_id || $p->money_transaction_id)
                $this->reject('Only an independently approved, unposted original tax liability may be paid.');
            $source=$this->source($p->payroll_period_id,$p->payroll_tax_policy_id);
            if(in_array($actorId,[
                (int)$p->requested_by,(int)$p->reviewed_by,
                (int)$source['period']->approved_by,
                (int)$source['policy']->approved_by,
            ],true))
                $this->reject('Tax payment executor must differ from the proposer, finance reviewer and original payroll/policy approvers.');
            $this->unchanged($p,$source);
            $this->available($p,$source);
            $bank=$this->paymentAccount($p,$source);
            $date=(string)$p->remit_on;
            if($date<$source['end'] || $date>today()->toDateString())
                $this->reject('The original signed remittance date is no longer valid.');
            $close->assertOpenDate($date);
            $liability=BigDecimal::zero();$cash=BigDecimal::zero();
            foreach(DB::table('journal_lines as line')
                ->join('journal_entries as entry','entry.id','=','line.journal_entry_id')
                ->where('entry.status','posted')
                ->where('entry.currency_id',$p->currency_id)
                ->whereDate('entry.entry_date','<=',$date)
                ->whereIn('line.ledger_account_id',[
                    $p->liability_ledger_id,$bank['cash']->id])
                ->get(['line.ledger_account_id','line.debit','line.credit']) as $line){
                if((int)$line->ledger_account_id===(int)$p->liability_ledger_id)
                    $liability=$liability->plus((string)$line->credit)
                        ->minus((string)$line->debit);
                else $cash=$cash->plus((string)$line->debit)
                        ->minus((string)$line->credit);
            }
            $amount=BigDecimal::of((string)$p->amount);
            if($liability->isLessThan($amount) || $cash->isLessThan($amount))
                $this->reject('Actual original-currency tax payable or bank/cash GL balance is insufficient for the approved transfer.');
            if(MoneyTransaction::where('submission_token',$p->submission_token)->exists())
                $this->reject('This tax source token already has an existing payment transaction.');
            DocumentSequence::firstOrCreate(
                ['document_type'=>'payroll_tax_remittance'],[
                    'prefix'=>'PTR-','next_number'=>1,'padding'=>7,'is_active'=>true,
                ]);
            $number=DocumentSequence::issue('payroll_tax_remittance');
            $journal=JournalEntry::create([
                'number'=>$number,'entry_date'=>$date,
                'reference'=>substr($p->evidence_reference,0,100),
                'description'=>'Approved source withholding remittance '.
                    $source['policy']->code.' / salary '.
                    substr((string)$source['period']->period_start,0,7),
                'currency_id'=>$p->currency_id,'created_by'=>$actorId,
                'status'=>'draft',
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$p->liability_ledger_id,
                    'debit'=>(string)$amount->toScale(4),'credit'=>'0'],
                ['ledger_account_id'=>$bank['cash']->id,
                    'debit'=>'0','credit'=>(string)$amount->toScale(4)],
            ]);
            app(\App\Services\Accounting\JournalPostingService::class)
                ->post($journal,$actorId);
            $transaction=MoneyTransaction::create([
                'submission_token'=>$p->submission_token,'type'=>'payment',
                'transaction_date'=>$date,
                'source_account_id'=>$bank['account']->id,
                'counterpart_ledger_account_id'=>$p->liability_ledger_id,
                'currency_id'=>$p->currency_id,
                'amount'=>(string)$amount->toScale(4),
                'reference'=>$p->evidence_reference,
                'description'=>'Employee withholding paid '.$number,
                'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
            ]);
            DB::table('payroll_tax_remittances')->where('id',$id)->update([
                'status'=>'posted','journal_entry_id'=>$journal->id,
                'money_transaction_id'=>$transaction->id,
                'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('payroll.tax_remittance.posted',
                'payroll_tax_remittance',$id,
                'Exact source-currency employee withholding liability paid from approved bank/cash source.',[
                    'journal_id'=>$journal->id,'money_id'=>$transaction->id,
                ]);
            return DB::table('payroll_tax_remittances')->where('id',$id)->first();
        });
    }
}
