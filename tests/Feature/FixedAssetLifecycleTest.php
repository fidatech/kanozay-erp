<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\CompanyWarehouse;
use App\Models\Currency;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FixedAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecords():array
    {
        foreach(['maintenance.view','maintenance.update','finance.update'] as $p)Permission::findOrCreate($p,'web');
        $user=User::factory()->create();
        $user->givePermissionTo(['maintenance.view','maintenance.update','finance.update']);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $category=FixedAssetCategory::create(['code'=>'MACH','name'=>'Machines','created_by'=>$user->id]);
        $asset=FixedAsset::create([
            'asset_tag'=>'FA-LIFE-1','name'=>'Mixer','fixed_asset_category_id'=>$category->id,
            'acquired_on'=>today()->subYear()->toDateString(),'acquisition_cost'=>'5000.0000',
            'currency_id'=>$currency->id,'source_reference'=>'SYNTH-FA-001','status'=>'active',
            'created_by'=>$user->id,
        ]);
        $branch=CompanyBranch::create(['code'=>'MAIN','name'=>'Main branch','is_active'=>true]);
        $a=CompanyWarehouse::create(['company_branch_id'=>$branch->id,'code'=>'WH-1','name'=>'Warehouse one','is_active'=>true]);
        $b=CompanyWarehouse::create(['company_branch_id'=>$branch->id,'code'=>'WH-2','name'=>'Warehouse two','is_active'=>true]);
        return [$user,$asset,$a,$b];
    }

    public function test_asset_service_request_progresses_and_becomes_immutable_after_completion():void
    {
        [$user,$asset]=$this->setupRecords();
        $data=['fixed_asset_id'=>$asset->id,'submission_token'=>(string)Str::uuid(),
            'type'=>'preventive','summary'=>'Replace filters before next batch'];
        $this->actingAs($user)->get(route('workspace.page',['assets','maintenance']))->assertOk()->assertSee('Asset lifecycle');
        $this->actingAs($user)->post(route('assets.lifecycle.maintenance.store'),$data)->assertRedirect();
        $id=DB::table('fixed_asset_maintenance_orders')->value('id');
        $this->actingAs($user)->post(route('assets.lifecycle.maintenance.update',$id),['status'=>'in_progress'])->assertRedirect();
        $this->actingAs($user)->post(route('assets.lifecycle.maintenance.update',$id),[
            'status'=>'completed','serviced_on'=>today()->toDateString(),
            'actual_cost'=>'120.2500','completion_notes'=>'Filter changed and operation tested',
        ])->assertRedirect();
        $this->assertDatabaseHas('fixed_asset_maintenance_orders',['id'=>$id,'status'=>'completed']);
        $this->actingAs($user)->post(route('assets.lifecycle.maintenance.update',$id),['status'=>'cancelled'])
            ->assertSessionHasErrors('status');
        $this->actingAs($user)->get(route('workspace.page',['assets','service-history']))
            ->assertOk()->assertSee('Replace filters');
    }

    public function test_asset_location_transfer_is_idempotent_and_audited():void
    {
        [$user,$asset,$a,$b]=$this->setupRecords();
        $token=(string)Str::uuid();
        $data=['destination_warehouse_id'=>$a->id,'moved_on'=>today()->toDateString(),
            'reason'=>'Moved to packing warehouse','submission_token'=>$token];
        $this->actingAs($user)->post(route('assets.lifecycle.transfer',$asset),$data)->assertRedirect();
        $this->actingAs($user)->post(route('assets.lifecycle.transfer',$asset),$data)->assertRedirect();
        $this->assertDatabaseCount('fixed_asset_location_movements',1);
        $data['destination_warehouse_id']=$b->id;
        $this->actingAs($user)->post(route('assets.lifecycle.transfer',$asset),$data)
            ->assertSessionHasErrors('submission_token');
        $this->assertSame($a->id,$asset->fresh()->company_warehouse_id);
    }

    public function test_disposal_retains_book_value_and_requires_financial_reconciliation():void
    {
        [$user,$asset]=$this->setupRecords();
        $this->actingAs($user)->post(route('assets.lifecycle.disposal',$asset),[
            'disposed_on'=>today()->toDateString(),'method'=>'scrap','proceeds'=>'0',
            'reason'=>'Approved equipment retirement after inspection','confirm_accounting_pending'=>1,
        ])->assertRedirect();
        $this->assertDatabaseHas('fixed_assets',['id'=>$asset->id,'status'=>'disposed']);
        $this->assertDatabaseHas('fixed_asset_disposals',[
            'fixed_asset_id'=>$asset->id,'book_value'=>'5000.0000','financial_status'=>'pending_reconciliation',
        ]);
        $this->assertDatabaseCount('journal_entries',0);
        $this->actingAs($user)->post(route('assets.lifecycle.disposal',$asset),[
            'disposed_on'=>today()->toDateString(),'method'=>'scrap','proceeds'=>'0',
            'reason'=>'Repeat record should be prevented','confirm_accounting_pending'=>1,
        ])->assertSessionHasErrors('asset');
    }

    public function test_unauthorized_users_cannot_read_or_write_asset_lifecycle():void
    {
        [$user,$asset]=$this->setupRecords();
        $stranger=User::factory()->create();
        $this->actingAs($stranger)->get(route('workspace.page',['assets','maintenance']))->assertForbidden();
        $this->actingAs($stranger)->post(route('assets.lifecycle.maintenance.store'),[])->assertForbidden();
        $this->actingAs($stranger)->post(route('assets.lifecycle.disposal',$asset),[])->assertForbidden();
    }
}
