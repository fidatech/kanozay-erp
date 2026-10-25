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

        $admin = Role::findOrCreate('Super Administrator', 'web');
        $admin->syncPermissions(Permission::where('guard_name', 'web')->get());

        foreach (['Administrator', 'Finance Manager', 'Production Manager', 'Inventory Officer', 'Laboratory Officer', 'Sales Officer', 'HR Officer', 'Viewer'] as $name) {
            Role::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
