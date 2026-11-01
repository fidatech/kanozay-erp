<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private function grant(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
    }

    private function employeeData(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-1001',
            'full_name' => 'Synthetic UAT Employee',
            'father_name' => 'Synthetic Parent',
            'gender' => 'Unspecified',
            'position' => 'UAT Clerk',
            'phone' => '000-000-0000',
            'national_id' => 'SYNTHETIC-NID',
            'taxpayer_id' => 'SYNTHETIC-TAX',
            'monthly_salary' => '12000.0000',
        ], $overrides);
    }

    public function test_hr_access_is_separate_from_administration_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('workspace.page', ['hr', 'employees']))->assertForbidden();
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertForbidden();
    }

    public function test_authorized_employee_profile_is_saved_with_encrypted_personal_fields(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.view', 'hr.create');

        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();

        $employee = EmployeeProfile::firstOrFail();
        $this->assertSame('Synthetic UAT Employee', $employee->full_name);
        $this->assertSame('SYNTHETIC-NID', $employee->national_id);
        $this->assertNotSame('Synthetic UAT Employee', $employee->getRawOriginal('full_name'));
        $this->assertNotSame('SYNTHETIC-NID', $employee->getRawOriginal('national_id'));
        $this->assertSame('12000.0000', $employee->monthly_salary);
        $this->assertDatabaseHas('activity_logs', ['action' => 'hr.employee.created', 'subject_id' => $employee->id]);
        $this->assertStringNotContainsString('Synthetic UAT Employee', json_encode(ActivityLog::firstOrFail()->details));
        $this->assertStringNotContainsString('SYNTHETIC-NID', json_encode(ActivityLog::firstOrFail()->details));
    }

    public function test_employee_number_must_be_unique_and_salary_precision_is_bounded(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.create');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData(['full_name' => 'Different Person']))->assertSessionHasErrors('employee_number');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData(['employee_number' => 'EMP-1002', 'monthly_salary' => '100.00001']))->assertSessionHasErrors('monthly_salary');
        $this->assertDatabaseCount('employee_profiles', 1);
    }

    public function test_persian_and_arabic_salary_digits_are_normalized(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.create');

        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData([
            'monthly_salary' => '۱۲٬۳۴۵٫۶۷',
        ]))->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-1001', 'monthly_salary' => '12345.6700']);
    }

    public function test_hr_view_filters_directory_and_status_change_does_not_resubmit_personal_data(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.view', 'hr.create', 'hr.update');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();
        $employee = EmployeeProfile::firstOrFail();

        $this->actingAs($user)->get(route('workspace.page', ['hr', 'employees']))
            ->assertOk()->assertSee('Synthetic UAT Employee')->assertDontSee('SYNTHETIC-NID')->assertDontSee('SYNTHETIC-TAX');
        $this->actingAs($user)->patch(route('hr.employees.update', $employee), ['is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('employee_profiles', ['id' => $employee->id, 'is_active' => 0]);
    }
}
