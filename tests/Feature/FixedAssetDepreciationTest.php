<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FixedAssetDepreciationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture():array
    {
        Permission::findOrCreate('finance.update','web');
        Permission::findOrCreate('maintenance.view','web');
        $user=User::factory()->create();
        $user->givePermissionTo(['finance.update','maintenance.view']);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $expense=LedgerAccount::create(['code'=>'6100','name'=>'Depreciation expense','type'=>'expense','is_active'=>true,'is_group'=>false]);
        $accum=LedgerAccount::create(['code'=>'1219','name'=>'Accumulated depreciation','type'=>'asset','is_active'=>true,'is_group'=>false]);
        $category=FixedAssetCategory::create(['code'=>'MACHINE','name'=>'Machinery','created_by'=>$user->id]);
        $asset=FixedAsset::create([
            'asset_tag'=>'M-TEST-001','name'=>'Synthetic mixer',
            'fixed_asset_category_id'=>$category->id,
            'acquired_on'=>now()->subMonths(5)->startOfMonth()->toDateString(),
            'acquisition_cost'=>'120.0000','currency_id'=>$currency->id,
            'source_reference'=>'SYNTHETIC-PO-1','status'=>'active','created_by'=>$user->id,
        ]);
        return [$user,$asset,$expense,$accum];
    }

    private function policy($user,$asset,$expense,$accum):array
    {
        $start=now()->subMonths(4)->format('Y-m');
        $data=['useful_life_months'=>3,'salvage_value'=>'0.0000',
            'start_month'=>$start,'expense_ledger_id'=>$expense->id,
            'accumulated_ledger_id'=>$accum->id];
        $this->actingAs($user)->post(route('assets.depreciation.policy',$asset),$data)->assertRedirect();
        return [$start,$data];
    }

    public function test_three_periods_post_balanced_journals_without_duplicate_depreciation():void
    {
        [$user,$asset,$expense,$accum]=$this->fixture();
        [$start,$policy]=$this->policy($user,$asset,$expense,$accum);
        foreach(range(0,2) as $i){
            $month=\Carbon\CarbonImmutable::parse($start.'-01')->addMonths($i)->format('Y-m');
            $this->actingAs($user)->post(route('assets.depreciation.post',$asset),['month'=>$month])->assertRedirect();
        }
        $this->assertDatabaseCount('fixed_asset_depreciation_entries',3);
        $this->assertSame('120.0000',(string)FixedAssetDepreciationEntry::sum('amount'));
        $this->assertDatabaseCount('journal_entries',3);
        $this->assertSame(3,JournalEntry::where('status','posted')->count());
        $this->actingAs($user)->post(route('assets.depreciation.post',$asset),['month'=>$start])->assertSessionHasErrors('month');
        $this->actingAs($user)->post(route('assets.depreciation.policy',$asset),$policy)->assertSessionHasErrors('policy');
    }

    public function test_depreciation_cannot_post_future_months_or_unapproved_policy():void
    {
        [$user,$asset,$expense,$accum]=$this->fixture();
        $this->actingAs($user)->post(route('assets.depreciation.post',$asset),['month'=>now()->subMonth()->format('Y-m')])
            ->assertSessionHasErrors('policy');
        $this->policy($user,$asset,$expense,$accum);
        $this->actingAs($user)->post(route('assets.depreciation.post',$asset),['month'=>now()->format('Y-m')])
            ->assertSessionHasErrors('month');
        $this->assertDatabaseCount('journal_entries',0);
    }

    public function test_requires_finance_permission_and_correct_ledger_types():void
    {
        [$user,$asset,$expense,$accum]=$this->fixture();
        $unauthorized=User::factory()->create();
        $this->actingAs($unauthorized)->get(route('workspace.page',['assets','depreciation']))->assertForbidden();
        $this->actingAs($unauthorized)->post(route('assets.depreciation.policy',$asset),[])->assertForbidden();
        $this->actingAs($user)->post(route('assets.depreciation.policy',$asset),[
            'useful_life_months'=>12,'salvage_value'=>'0',
            'start_month'=>now()->subMonth()->format('Y-m'),
            'expense_ledger_id'=>$accum->id,'accumulated_ledger_id'=>$expense->id,
        ])->assertSessionHasErrors(['expense_ledger_id','accumulated_ledger_id']);
    }
}
