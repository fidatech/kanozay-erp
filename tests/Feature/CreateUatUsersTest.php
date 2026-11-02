<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUatUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_users_are_opt_in_and_have_scoped_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(1, $this->artisan('kanozay:uat-users')->run());
        $this->assertSame(0, User::count());
        $this->assertSame(0, $this->artisan('kanozay:uat-users', ['--confirm-isolated' => true])->run());
        $this->assertSame(8, User::count());
        $this->assertTrue(User::where('email', 'uat-finance@kanozay.invalid')->firstOrFail()->hasRole('Finance Manager'));
        $this->assertFalse(User::where('email', 'uat-viewer@kanozay.invalid')->firstOrFail()->can('finance.update'));
        $this->assertSame(1, $this->artisan('kanozay:uat-users', ['--confirm-isolated' => true])->run());
        $this->assertSame(8, User::count());
    }
}
