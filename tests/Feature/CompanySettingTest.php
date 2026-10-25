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
}
