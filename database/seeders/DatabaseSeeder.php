<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Install reference roles and permissions only. Never seed a predictable
        // account into an environment that may be reachable by client users.
        $this->call(RolePermissionSeeder::class);
    }
}
