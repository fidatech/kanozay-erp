<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModuleRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_roles_receive_scoped_permissions_without_legacy_admin_access(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $finance = Role::findByName('Finance Manager', 'web');
        $this->assertTrue($finance->hasPermissionTo('finance.view'));
        $this->assertTrue($finance->hasPermissionTo('finance.update'));
        $this->assertTrue($finance->hasPermissionTo('reports.view'));
        $this->assertFalse($finance->hasPermissionTo('administration.view'));
        $this->assertFalse($finance->hasPermissionTo('inventory.view'));

        $production = Role::findByName('Production Manager', 'web');
        $this->assertTrue($production->hasPermissionTo('manufacturing.create'));
        $this->assertTrue($production->hasPermissionTo('inventory.update'));
        $this->assertFalse($production->hasPermissionTo('administration.view'));

        $viewer = Role::findByName('Viewer', 'web');
        $this->assertTrue($viewer->hasPermissionTo('reports.view'));
        $this->assertFalse($viewer->hasPermissionTo('finance.update'));
    }

    public function test_scoped_module_roles_can_open_their_workspace_pages(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $financeUser = \App\Models\User::factory()->create();
        $financeUser->assignRole(Role::findByName('Finance Manager', 'web'));

        $this->actingAs($financeUser)
            ->get(route('workspace.page', ['module' => 'reports', 'page' => 'finance']))
            ->assertOk()
            ->assertSee('Financial Reports');

        $inventoryUser = \App\Models\User::factory()->create();
        $inventoryUser->assignRole(Role::findByName('Inventory Officer', 'web'));
        $this->actingAs($inventoryUser)
            ->get(route('workspace.page', ['module' => 'inventory', 'page' => 'materials']))
            ->assertOk()
            ->assertSee('Raw Materials');

        $unprivilegedUser = \App\Models\User::factory()->create();
        $this->actingAs($unprivilegedUser)
            ->get(route('workspace.page', ['module' => 'sales', 'page' => 'invoices']))
            ->assertForbidden();
    }

    public function test_seeding_preserves_permissions_already_configured_on_a_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $finance = Role::findByName('Finance Manager', 'web');
        $extra = Permission::findByName('finance.delete', 'web');
        $finance->givePermissionTo($extra);

        $this->seed(RolePermissionSeeder::class);

        $finance->refresh();
        $this->assertTrue($finance->hasPermissionTo('finance.delete'));
        $this->assertFalse($finance->hasPermissionTo('administration.view'));
    }
}
