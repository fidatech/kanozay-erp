<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = [
            'dashboard', 'purchases', 'inventory', 'manufacturing', 'laboratory',
            'sales', 'finance', 'shareholders', 'hr', 'maintenance', 'reports',
            'administration', 'users', 'roles',
        ];

        foreach ($modules as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($module.'.'.$action, 'web');
            }
        }
        Permission::findOrCreate('hr.import', 'web');

        $admin = Role::findOrCreate('Super Administrator', 'web');
        $admin->syncPermissions(Permission::where('guard_name', 'web')->get());

        $rolePermissions = [
            'Administrator' => ['dashboard.view', 'administration.view', 'administration.update', 'users.view', 'users.create', 'users.update', 'roles.view', 'roles.create', 'roles.update'],
            'Finance Manager' => ['dashboard.view', 'finance.view', 'finance.create', 'finance.update', 'reports.view'],
            'Production Manager' => ['dashboard.view', 'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'inventory.view', 'inventory.update', 'laboratory.view', 'laboratory.create', 'laboratory.update'],
            'Inventory Officer' => ['dashboard.view', 'inventory.view', 'inventory.create', 'inventory.update', 'purchases.view', 'purchases.update'],
            'Laboratory Officer' => ['dashboard.view', 'laboratory.view', 'laboratory.create', 'laboratory.update', 'manufacturing.view'],
            'Sales Officer' => ['dashboard.view', 'sales.view', 'sales.create', 'sales.update'],
            'HR Officer' => ['dashboard.view', 'hr.view', 'hr.create', 'hr.update'],
            'Viewer' => ['dashboard.view', 'reports.view'],
        ];

        foreach ($rolePermissions as $name => $permissionNames) {
            $role = Role::findOrCreate($name, 'web');
            // Seed initial least-privilege access only; preserve any role an administrator configured.
            if ($role->permissions()->count() === 0) {
                $role->syncPermissions(Permission::where('guard_name', 'web')->whereIn('name', $permissionNames)->get());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
