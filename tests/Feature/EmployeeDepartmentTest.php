<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeDepartment;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeDepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_registration_assignment_and_deactivation_preserve_employee(): void
    {
        $user = User::factory()->create();
        foreach (['hr.view', 'hr.create', 'hr.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.view', 'hr.create', 'hr.update']);
        $this->actingAs($user)->post(route('hr.departments.store'), ['code' => 'SYN-QC', 'name' => 'Synthetic quality'])->assertRedirect();
        $this->actingAs($user)->post(route('hr.departments.store'), ['code' => 'SYN-QC', 'name' => 'Duplicate'])->assertSessionHasErrors('code');
        $department = EmployeeDepartment::sole();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-DEPT-01', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $this->actingAs($user)->patch(route('hr.employees.department', $employee), ['department_id' => $department->id])->assertRedirect();
        $this->assertSame($department->id, $employee->fresh()->department_id);
        $this->actingAs($user)->patch(route('hr.departments.update', $department), ['name' => 'Synthetic QA', 'is_active' => '0'])->assertRedirect();
        $this->actingAs($user)->get(route('workspace.page', ['hr', 'employees']))->assertOk()->assertSee('Synthetic QA');
        $this->actingAs($user)->post(route('hr.employees.store'), [
            'employee_number' => 'SYN-DEPT-02', 'full_name' => 'Other Synthetic Employee',
            'monthly_salary' => '100', 'salary_currency_id' => $currency->id,
            'department_id' => $department->id,
        ])->assertSessionHasErrors('department_id');
        $this->assertSame(1, EmployeeProfile::count());
    }

    public function test_department_mutation_requires_hr_permissions(): void
    {
        $this->actingAs(User::factory()->create())->post(route('hr.departments.store'), [])->assertForbidden();
    }
}
