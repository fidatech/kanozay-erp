<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_settings_require_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.company.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.company.update'), ['name'=>'Changed','country'=>'Afghanistan','timezone'=>'Asia/Kabul','default_locale'=>'en','date_format'=>'gregorian'])->assertForbidden();
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_authorized_admin_can_create_and_update_single_company_profile(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        Permission::findOrCreate('administration.update','web');
        $user->givePermissionTo(['administration.view','administration.update']);
        $data = ['name'=>'Kanozay Foods','country'=>'Afghanistan','timezone'=>'Asia/Kabul','default_locale'=>'en','date_format'=>'gregorian'];
        $this->actingAs($user)->get(route('admin.company.edit'))->assertOk();
        $this->actingAs($user)->put(route('admin.company.update'), $data)->assertRedirect();
        $this->assertSame('Kanozay Foods', CompanySetting::firstOrFail()->name);
        $this->actingAs($user)->put(route('admin.company.update'), array_merge($data, ['name'=>'Kanozay Manufacturing']))->assertRedirect();
        $this->assertDatabaseCount('company_settings', 1);
        $this->assertSame('Kanozay Manufacturing', CompanySetting::firstOrFail()->name);
    }
    public function test_branch_and_warehouse_creation_and_inactivation_safeguards(): void
    {
        $admin=User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('administration.update','web'));
        $admin->givePermissionTo(Permission::findOrCreate('administration.view','web'));
        $this->actingAs($admin)->post(route('admin.company.branches.store'), ['code'=>'KBL','name'=>'Kabul Factory'])->assertRedirect();
        $branch=\App\Models\CompanyBranch::where('code','KBL')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.company.warehouses.store'), ['company_branch_id'=>$branch->id,'code'=>'RAW','name'=>'Raw Materials'])->assertRedirect();
        $warehouse=\App\Models\CompanyWarehouse::where('code','RAW')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.company.branches.update',$branch),['name'=>'Kabul Factory','is_active'=>0])->assertSessionHasErrors('branch');
        $this->assertTrue($branch->fresh()->is_active);
        $this->actingAs($admin)->put(route('admin.company.warehouses.update',$warehouse),['name'=>'Raw Materials','is_active'=>0])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.company.branches.update',$branch),['name'=>'Kabul Factory','is_active'=>0])->assertRedirect();
        $this->assertFalse($branch->fresh()->is_active);
        $this->actingAs($admin)->put(route('admin.company.warehouses.update',$warehouse),['name'=>'Raw Materials','is_active'=>1])->assertSessionHasErrors('warehouse');
        $this->actingAs($admin)->get(route('admin.company.edit'))->assertOk()->assertSee('Kabul Factory');
    }

    public function test_fiscal_year_start_is_validated_and_stored_without_defaults(): void
    {
        $admin=User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('administration.update','web'));
        $data=['name'=>'Kanozay','country'=>'Afghanistan','timezone'=>'Asia/Kabul','default_locale'=>'en','date_format'=>'gregorian'];
        $this->actingAs($admin)->put(route('admin.company.update'),$data+['fiscal_start_month'=>2,'fiscal_start_day'=>30])->assertSessionHasErrors('fiscal_start_day');
        $this->assertDatabaseCount('company_settings',0);
        $this->actingAs($admin)->put(route('admin.company.update'),$data+['fiscal_start_month'=>3,'fiscal_start_day'=>21])->assertRedirect();
        $this->assertSame(3,\App\Models\CompanySetting::firstOrFail()->fiscal_start_month);
    }

    public function test_employee_without_configuration_permission_cannot_modify_branches(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->post(route('admin.company.branches.store'),['code'=>'X','name'=>'X'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.company.warehouses.store'),[])->assertForbidden();
    }

}
