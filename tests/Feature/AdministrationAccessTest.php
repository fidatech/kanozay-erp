<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_manager_cannot_grant_permissions_they_do_not_possess(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Elevated UAT', 'permissions' => ['roles.create', 'users.delete'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Elevated UAT']);

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Limited UAT', 'permissions' => ['dashboard.view'],
        ])->assertRedirect();
        $role = Role::findByName('Limited UAT', 'web');
        $this->actingAs($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Limited UAT', 'permissions' => ['dashboard.view', 'roles.delete'],
        ])->assertForbidden();
        $this->assertFalse($role->fresh()->hasPermissionTo('roles.delete'));
    }

    public function test_super_administrator_may_assign_permissions_and_is_protected_from_self_demotion(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $super = User::factory()->create();
        $super->assignRole('Super Administrator');
        $this->actingAs($super)->post(route('admin.roles.store'), [
            'name' => 'Auditor UAT', 'permissions' => ['users.delete'],
        ])->assertRedirect();
        $this->assertTrue(Role::findByName('Auditor UAT', 'web')->hasPermissionTo('users.delete'));
        $this->actingAs($super)->put(route('admin.users.update', $super), [
            'name' => $super->name, 'email' => $super->email, 'role' => 'Viewer',
        ])->assertForbidden();
        $this->assertTrue($super->fresh()->hasRole('Super Administrator'));
        $this->actingAs($super)->get(route('admin.users.index'))
            ->assertOk()->assertDontSee('data-modal-open="edit-user-'.$super->id.'"', false)
            ->assertDontSee('action="'.route('admin.users.destroy', $super).'"', false)
            ->assertSee('Protected account');
    }

    public function test_unprivileged_user_cannot_manage_users_or_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.users.store'), [])->assertForbidden();
    }
    public function test_home_redirects_to_login_or_dashboard_and_forbidden_page_uses_application_navigation(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer)->get('/')->assertRedirect(route('dashboard'));
        $this->actingAs($viewer)->get(route('admin.users.index'))
            ->assertForbidden()->assertSee('Access denied')->assertSee('Return to dashboard');
    }

    public function test_user_management_form_has_scrollable_native_role_selector(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $super = User::factory()->create();
        $super->assignRole('Super Administrator');
        $this->actingAs($super)->get(route('admin.users.index'))
            ->assertOk()->assertSee('user-dialog')->assertSee('data-no-select2="1"', false);
    }

}
