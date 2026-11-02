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
        ])->assertSessionHasErrors('role');
        $this->assertTrue($super->fresh()->hasRole('Super Administrator'));
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
}
