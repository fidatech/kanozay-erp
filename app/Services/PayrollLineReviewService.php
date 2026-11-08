<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review is evidence, never an automatic salary deduction. A review becomes
 * stale whenever its salary, employment, attendance, leave or component
 * evidence changes before approval.
 */
class PayrollLineReviewService
{
    public function fingerprint(PayrollLine $line, PayrollPeriod $period): string
    {
        $employee=$line->employee()->firstOrFail();
        $start=$period->period_start->toDateString();
        $end=$period->period_start->copy()->endOfMonth()->toDateString();
        $attendance=DB::table('employee_attendance')
            ->where('employee_profile_id',$employee->id)
            ->whereBetween('attendance_date',[$start,$end])
            ->orderBy('attendance_date')->orderBy('id')
            ->get(['id','attendance_date','status','notes','correction_reason','corrected_by'])
            ->map(fn($row)=>(array)$row)->all();
        $leave=DB::table('employee_leave_requests')
            ->where('employee_profile_id',$employee->id)
            ->where('starts_on','<=',$end)->where('ends_on','>=',$start)
            ->orderBy('id')
            ->get(['id','starts_on','ends_on','status','decided_by','decided_at'])
            ->map(fn($row)=>(array)$row)->all();
        $components=DB::table('payroll_line_components')
            ->where('payroll_line_id',$line->id)->orderBy('id')
            ->get(['id','kind','label','amount','ledger_account_id',
                'approval_reference','reason'])->map(fn($row)=>(array)$row)->all();

        return hash('sha256',json_encode([
            'period'=>$start,'currency_id'=>(int)$period->currency_id,
            'employee_id'=>(int)$employee->id,
            'hired_on'=>$employee->hired_on?->toDateString(),
            'separated_on'=>$employee->separated_on?->toDateString(),
            'is_active'=>(bool)$employee->is_active,
            'salary_snapshot'=>(string)$line->salary_snapshot,
            'payable_amount'=>(string)$line->payable_amount,
            'adjustment_reason'=>$line->adjustment_reason,
            'attendance'=>$attendance,'leave'=>$leave,'components'=>$components,
        ],JSON_THROW_ON_ERROR));
    }

    public function review(PayrollPeriod $period,PayrollLine $line,array $data,int $actorId):void
    {
        DB::transaction(function()use($period,$line,$data,$actorId){
            $locked=PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='draft'){
                throw ValidationException::withMessages([
                    'payroll'=>'Only draft salary lines can receive a new evidence review.',
                ]);
            }
            $item=PayrollLine::where('payroll_period_id',$locked->id)
                ->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $fingerprint=$this->fingerprint($item,$locked);
            $record=[
                'payroll_line_id'=>$item->id,
                'evidence_hash'=>$fingerprint,
                'reference'=>trim($data['reference']),
                'review_notes'=>trim($data['review_notes']),
                'reviewed_by'=>$actorId,
                'reviewed_at'=>now(),
                'updated_at'=>now(),
            ];
            DB::table('payroll_line_reviews')->updateOrInsert(
                ['payroll_line_id'=>$item->id],
                $record+['created_at'=>now()]
            );
            DB::table('payroll_line_review_events')->insert([
                'payroll_line_id'=>$item->id,'evidence_hash'=>$fingerprint,
                'reference'=>$record['reference'],'review_notes'=>$record['review_notes'],
                'reviewed_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('payroll.line.reviewed','payroll_line',$item->id,
                'Employee payroll salary, attendance, leave and component evidence reviewed.',
                ['period_id'=>$locked->id,'review_reference'=>$record['reference'],
                 'evidence_hash'=>$fingerprint]);
        });
    }

    public function assertPeriodReviewed(PayrollPeriod $period, $lines):void
    {
        $reviews=DB::table('payroll_line_reviews')
            ->whereIn('payroll_line_id',$lines->pluck('id'))
            ->get()->keyBy('payroll_line_id');
        foreach($lines as $line){
            $review=$reviews->get($line->id);
            if(!$review){
                throw ValidationException::withMessages([
                    'payroll'=>'Employee '.$line->employee->employee_number.
                    ' requires a reviewed attendance/salary record before approval.',
                ]);
            }
            if(!hash_equals((string)$review->evidence_hash,$this->fingerprint($line,$period))){
                throw ValidationException::withMessages([
                    'payroll'=>'Evidence for employee '.$line->employee->employee_number.
                    ' changed after its review. Recheck and review the employee again before approval.',
                ]);
            }
        }
    }

    public function overview(PayrollPeriod $period,$lines):array
    {
        $reviews=DB::table('payroll_line_reviews')
            ->whereIn('payroll_line_id',$lines->pluck('id'))
            ->get()->keyBy('payroll_line_id');
        $result=[];
        foreach($lines as $line){
            $review=$reviews->get($line->id);
            $result[$line->id]=[
                'status'=>$review?
                  (hash_equals((string)$review->evidence_hash,$this->fingerprint($line,$period))
                    ?'reviewed':'stale'):'pending',
                'reference'=>$review?->reference,
                'reviewed_at'=>$review?->reviewed_at,
            ];
        }
        return $result;
    }
}
