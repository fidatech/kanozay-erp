<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_reference_roles_without_a_default_user(): void
    {
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

        $this->assertSame(0, User::query()->count());
        $this->assertTrue(Role::query()->where('name', 'Super Administrator')->exists());
        $this->assertTrue(Role::query()->where('name', 'Viewer')->exists());
    }
}
