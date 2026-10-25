<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_access_administration(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
        $this->get('/admin/roles')->assertRedirect('/login');
    }

    public function test_unprivileged_user_cannot_access_administration(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
        $this->actingAs($user)->post('/admin/users', [])->assertForbidden();
    }

    public function test_administrator_can_view_management_pages(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/roles')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_admin_cannot_delete_self(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $this->actingAs($user)->delete(route('admin.users.destroy', $user))->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_admin_can_create_employee(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Administrator');
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Test Employee', 'email' => 'employee@example.test',
            'password' => 'SecurePass12345!', 'password_confirmation' => 'SecurePass12345!',
            'role' => 'Viewer',
        ])->assertRedirect();
        $employee = User::where('email', 'employee@example.test')->firstOrFail();
        $this->assertTrue($employee->hasRole('Viewer'));
    }
}
