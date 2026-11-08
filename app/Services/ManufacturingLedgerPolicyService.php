<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManufacturingLedgerPolicyService
{
    private const ACCOUNT_TYPES=[
        'material_inventory_ledger_id'=>'asset',
        'work_in_progress_ledger_id'=>'asset',
        'finished_inventory_ledger_id'=>'asset',
        'cogs_ledger_id'=>'expense',
    ];

    public function validateAccounts(object|array $data):void
    {
        $get=fn(string $field)=>(int)(is_array($data)?$data[$field]:$data->{$field});
        $ids=[];
        foreach(self::ACCOUNT_TYPES as $field=>$type){
            $id=$get($field);
            $ledger=LedgerAccount::whereKey($id)->first();
            if(!$ledger || !$ledger->is_active || $ledger->is_group || $ledger->type!==$type){
                throw ValidationException::withMessages([$field=>'Choose an active '.$type.
                    ' posting ledger, not a summary or inactive account.']);
            }
            if(DB::table('entity_ledger_accounts')->where('ledger_account_id',$id)->exists()){
                throw ValidationException::withMessages([$field=>
                    'Manufacturing GL policy accounts must be independent of customer, supplier, employee or cashbox-specific ledger mappings.']);
            }
            $ids[]=$id;
        }
        if(count(array_unique($ids))!==4){
            throw ValidationException::withMessages(['ledger_accounts'=>
                'Raw material, work in progress, finished inventory and COGS must use four distinct ledger accounts.']);
        }
    }

    public function create(array $data,int $actorId):int
    {
        return DB::transaction(function()use($data,$actorId){
            $this->validateAccounts($data);
            if(DB::table('manufacturing_cost_ledger_policies')
                ->where('currency_id',$data['currency_id'])->exists()){
                throw ValidationException::withMessages(['currency_id'=>
                    'A costing policy already exists for this currency. Policies require a separately authorized review and cannot be silently replaced.']);
            }
            $id=DB::table('manufacturing_cost_ledger_policies')->insertGetId([
                'currency_id'=>$data['currency_id'],
                'material_inventory_ledger_id'=>$data['material_inventory_ledger_id'],
                'work_in_progress_ledger_id'=>$data['work_in_progress_ledger_id'],
                'finished_inventory_ledger_id'=>$data['finished_inventory_ledger_id'],
                'cogs_ledger_id'=>$data['cogs_ledger_id'],
                'status'=>'draft',
                'authorization_reference'=>trim($data['authorization_reference']),
                'notes'=>trim($data['notes']),'created_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('manufacturing.cost_policy.submitted',
                'manufacturing_cost_ledger_policy',$id,
                'Proposed currency-specific manufacturing asset and COGS GL mapping.',
                ['currency_id'=>$data['currency_id']]);
            return $id;
        });
    }

    public function approve(int $policyId,string $reference,int $actorId):void
    {
        DB::transaction(function()use($policyId,$reference,$actorId){
            $policy=DB::table('manufacturing_cost_ledger_policies')
                ->where('id',$policyId)->lockForUpdate()->first();
            abort_unless($policy,404);
            if($policy->status!=='draft'){
                throw ValidationException::withMessages(['policy'=>'Only a draft accounting mapping can be approved.']);
            }
            if((int)$policy->created_by===$actorId){
                throw ValidationException::withMessages(['policy'=>'A separate finance reviewer must authorize the manufacturing ledger policy.']);
            }
            $this->validateAccounts($policy);
            DB::table('manufacturing_cost_ledger_policies')->where('id',$policyId)->update([
                'status'=>'approved','approved_by'=>$actorId,'approved_at'=>now(),
                'updated_at'=>now(),
            ]);
            ActivityLog::record('manufacturing.cost_policy.approved',
                'manufacturing_cost_ledger_policy',$policyId,
                'Independent approval of manufacturing GL ledger mappings.',
                ['approval_reference'=>$reference,'currency_id'=>$policy->currency_id]);
        });
    }

    public function approved(int $currencyId):object
    {
        $policy=DB::table('manufacturing_cost_ledger_policies')
            ->where('currency_id',$currencyId)->first();
        if(!$policy || $policy->status!=='approved'){
            throw ValidationException::withMessages(['currency_id'=>
                'Finance has not independently approved original-currency manufacturing inventory, WIP and COGS ledger accounts.']);
        }
        $this->validateAccounts($policy);
        return $policy;
    }
}
