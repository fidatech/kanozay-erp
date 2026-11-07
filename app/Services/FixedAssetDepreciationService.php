<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationPolicy;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetDepreciationService
{
    public function __construct(private JournalPostingService $posting) {}

    public function policy(FixedAsset $asset,array $data,int $userId):FixedAssetDepreciationPolicy
    {
        return DB::transaction(function()use($asset,$data,$userId) {
            $asset=FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if($asset->status!=='active')throw ValidationException::withMessages(['asset'=>'Only active assets can be depreciated.']);
            $existing=FixedAssetDepreciationPolicy::where('fixed_asset_id',$asset->id)->lockForUpdate()->first();
            if($existing && $existing->entries()->exists())
                throw ValidationException::withMessages(['policy'=>'Depreciation policy cannot be changed once a month has been posted.']);

            if(BigDecimal::of((string)$data['salvage_value'])->isGreaterThanOrEqualTo((string)$asset->acquisition_cost))
                throw ValidationException::withMessages(['salvage_value'=>'Salvage value must be lower than acquisition cost.']);
            $start=CarbonImmutable::parse($data['start_month'].'-01');
            if($start->isBefore(CarbonImmutable::parse($asset->acquired_on)->startOfMonth()))
                throw ValidationException::withMessages(['start_month'=>'Depreciation cannot start before acquisition.']);

            $accountIds=[(int)$data['expense_ledger_id'],(int)$data['accumulated_ledger_id']];
            if($accountIds[0]===$accountIds[1])throw ValidationException::withMessages(['accounts'=>'Expense and accumulated-depreciation accounts must differ.']);
            $accounts=LedgerAccount::whereIn('id',$accountIds)->where('is_active',true)->where('is_group',false)->get()->keyBy('id');
            if($accounts->count()!==2 || $accounts[$accountIds[0]]->type!=='expense' || $accounts[$accountIds[1]]->type!=='asset')
                throw ValidationException::withMessages(['accounts'=>'Select an expense account and a contra-asset ledger account.']);
            if(DB::table('entity_ledger_accounts')->whereIn('ledger_account_id',$accountIds)->exists())
                throw ValidationException::withMessages(['accounts'=>'Mapped customer, supplier and financial ledgers cannot be used for depreciation.']);

            $values=[
                'fixed_asset_id'=>$asset->id,'useful_life_months'=>$data['useful_life_months'],
                'salvage_value'=>(string)BigDecimal::of((string)$data['salvage_value'])->toScale(4),
                'start_month'=>$start->toDateString(),
                'expense_ledger_id'=>$accountIds[0],'accumulated_ledger_id'=>$accountIds[1],
                'approved_by'=>$userId,
            ];
            $policy=$existing ?: new FixedAssetDepreciationPolicy();
            $policy->fill($values)->save();
            ActivityLog::record('fixed_asset.depreciation.policy','fixed_asset',$asset->id,
                'Straight-line depreciation policy approved for '.$asset->asset_tag);
            return $policy;
        });
    }

    public function post(FixedAsset $asset,string $month,int $userId):FixedAssetDepreciationEntry
    {
        return DB::transaction(function()use($asset,$month,$userId) {
            $asset=FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if($asset->status!=='active')throw ValidationException::withMessages(['asset'=>'Inactive assets cannot be depreciated.']);
            $policy=FixedAssetDepreciationPolicy::where('fixed_asset_id',$asset->id)->lockForUpdate()->first();
            if(!$policy)throw ValidationException::withMessages(['policy'=>'Approve a depreciation policy before posting.']);

            $period=CarbonImmutable::createFromFormat('!Y-m-d',$month.'-01');
            if(!$period || $period->format('Y-m')!==$month)
                throw ValidationException::withMessages(['month'=>'Invalid accounting month.']);
            $start=CarbonImmutable::parse($policy->start_month)->startOfMonth();
            $index=($period->year-$start->year)*12+($period->month-$start->month);
            if($index<0 || $index>=(int)$policy->useful_life_months)
                throw ValidationException::withMessages(['month'=>'Month is outside the approved useful life.']);
            if($period->endOfMonth()->isAfter(today()))
                throw ValidationException::withMessages(['month'=>'Only completed calendar months can be depreciated.']);

            if(FixedAssetDepreciationEntry::where('fixed_asset_id',$asset->id)->whereDate('period_month',$period->toDateString())->exists())
                throw ValidationException::withMessages(['month'=>'This asset has already been depreciated for the month.']);

            $posted=FixedAssetDepreciationEntry::where('fixed_asset_id',$asset->id)->count();
            if($posted!==$index)
                throw ValidationException::withMessages(['month'=>'Post depreciation months in chronological order without gaps.']);

            $base=BigDecimal::of((string)$asset->acquisition_cost)->minus((string)$policy->salvage_value);
            $monthAmount=$base->dividedBy((string)$policy->useful_life_months,4,RoundingMode::HALF_UP);
            if($index===(int)$policy->useful_life_months-1) {
                $monthAmount=$base->minus($monthAmount->multipliedBy((string)$index))->toScale(4);
            }
            if(!$monthAmount->isPositive())
                throw ValidationException::withMessages(['policy'=>'Calculated monthly depreciation is not positive.']);

            $journal=JournalEntry::create([
                'number'=>'FA-DEP-'.$asset->id.'-'.$period->format('Ym'),
                'entry_date'=>$period->endOfMonth()->toDateString(),
                'reference'=>$asset->asset_tag,
                'description'=>'Straight-line depreciation for '.$asset->asset_tag.' / '.$month,
                'status'=>'draft','currency_id'=>$asset->currency_id,'created_by'=>$userId,
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$policy->expense_ledger_id,'debit'=>(string)$monthAmount,'credit'=>'0','memo'=>'Depreciation expense'],
                ['ledger_account_id'=>$policy->accumulated_ledger_id,'debit'=>'0','credit'=>(string)$monthAmount,'memo'=>'Accumulated depreciation'],
            ]);
            $this->posting->post($journal,$userId);

            $entry=FixedAssetDepreciationEntry::create([
                'fixed_asset_id'=>$asset->id,'policy_id'=>$policy->id,
                'period_month'=>$period->toDateString(),'amount'=>(string)$monthAmount,
                'journal_entry_id'=>$journal->id,'posted_by'=>$userId,
            ]);
            ActivityLog::record('fixed_asset.depreciation.posted','fixed_asset',$asset->id,
                'Depreciation posted for '.$asset->asset_tag.' / '.$month,['journal'=>$journal->number,'amount'=>(string)$monthAmount,'currency_id'=>$asset->currency_id]);
            return $entry;
        });
    }
}
