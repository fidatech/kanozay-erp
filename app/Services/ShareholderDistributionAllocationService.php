<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Shareholder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit board-approved distribution allocations. Ownership percentages,
 * capital deposits and individual cash balances are never assumed to define
 * an entitlement to a profit payout.
 */
class ShareholderDistributionAllocationService
{
    public function save(array $data,int $actorId):void
    {
        DB::transaction(function()use($data,$actorId){
            $resolution=DB::table('shareholder_resolutions')
                ->where('id',$data['shareholder_resolution_id'])
                ->lockForUpdate()->first();
            abort_unless($resolution,404);
            if($resolution->purpose!=='distribution' || $resolution->status!=='draft'){
                throw ValidationException::withMessages([
                    'shareholder_resolution_id'=>'Distribution entitlements can only be amended while the resolution is a draft.',
                ]);
            }
            $party=Shareholder::whereKey($data['shareholder_id'])
                ->lockForUpdate()->firstOrFail();
            if(!$party->is_active ||
                !$party->currencies()->whereKey($resolution->currency_id)->exists()){
                throw ValidationException::withMessages([
                    'shareholder_id'=>'The payee must be active with the distribution currency enabled.',
                ]);
            }
            $amount=BigDecimal::of((string)$data['authorized_amount'])->toScale(4);
            if(!$amount->isPositive()){
                throw ValidationException::withMessages([
                    'authorized_amount'=>'Provide a positive entitlement. Do not use zero to erase documented board allocations.',
                ]);
            }
            $existing=DB::table('shareholder_distribution_allocations')
                ->where('shareholder_resolution_id',$resolution->id)
                ->where('shareholder_id',$party->id)->first();
            $total=BigDecimal::zero();
            foreach(DB::table('shareholder_distribution_allocations')
                ->where('shareholder_resolution_id',$resolution->id)->get() as $row){
                if($existing && (int)$row->id===(int)$existing->id)continue;
                $total=$total->plus((string)$row->authorized_amount);
            }
            if($total->plus($amount)->isGreaterThan((string)$resolution->distribution_cap)){
                throw ValidationException::withMessages([
                    'authorized_amount'=>'Sum of individual entitlements exceeds the board resolution ceiling.',
                ]);
            }
            $before=$existing?->authorized_amount??'0';
            if($existing && BigDecimal::of((string)$before)->isEqualTo($amount) &&
                $existing->approval_reference===trim($data['approval_reference'])){
                return;
            }
            if($existing){
                DB::table('shareholder_distribution_allocations')
                    ->where('id',$existing->id)->update([
                        'authorized_amount'=>(string)$amount,
                        'approval_reference'=>trim($data['approval_reference']),
                        'recorded_by'=>$actorId,'updated_at'=>now(),
                    ]);
                $id=$existing->id;
            }else{
                $id=DB::table('shareholder_distribution_allocations')->insertGetId([
                    'shareholder_resolution_id'=>$resolution->id,
                    'shareholder_id'=>$party->id,
                    'authorized_amount'=>(string)$amount,
                    'approval_reference'=>trim($data['approval_reference']),
                    'recorded_by'=>$actorId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('shareholder_distribution_allocation_events')->insert([
                'shareholder_distribution_allocation_id'=>$id,
                'previous_amount'=>$before,'new_amount'=>(string)$amount,
                'approval_reference'=>trim($data['approval_reference']),
                'recorded_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('shareholder.distribution_entitlement.amended',
                'shareholder_resolution',$resolution->id,
                'Documented per-recipient distribution ceiling before independent board approval.',[
                    'shareholder_id'=>$party->id,'authorized_amount'=>(string)$amount,
                    'previous_amount'=>$before,'approval_reference'=>$data['approval_reference'],
                ]);
        });
    }

    public function assertReadyForApproval(object $resolution,int $reviewerId):void
    {
        if($resolution->purpose!=='distribution')return;
        $rows=DB::table('shareholder_distribution_allocations')
            ->where('shareholder_resolution_id',$resolution->id)->get();
        if($rows->isEmpty()){
            throw ValidationException::withMessages([
                'decision'=>'A profit distribution needs an explicit, referenced entitlement for each authorized recipient before board approval.',
            ]);
        }
        $total=BigDecimal::zero();
        foreach($rows as $row){
            if((int)$row->recorded_by===$reviewerId){
                throw ValidationException::withMessages([
                    'decision'=>'A distribution allocation cannot be entered or last amended by the person approving its board resolution. Ask a separate authorized reviewer.',
                ]);
            }
            $total=$total->plus((string)$row->authorized_amount);
        }
        if(!$total->isPositive() || $total->isGreaterThan((string)$resolution->distribution_cap)){
            throw ValidationException::withMessages([
                'decision'=>'Allocated shareholder entitlements must be positive and must not exceed the signed distribution ceiling.',
            ]);
        }
    }
}
