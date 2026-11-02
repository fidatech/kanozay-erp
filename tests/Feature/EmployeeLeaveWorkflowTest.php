<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeLeaveRequest;
use App\Models\EmployeeProfile;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeLeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_leave_is_blocked_and_decision_is_single_use(): void
    {
        $user = User::factory()->create();
        foreach (['hr.view', 'hr.create', 'hr.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.view', 'hr.create', 'hr.update']);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-LEAVE-01', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $payload = [
            'employee_profile_id' => $employee->id,
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'reason' => 'Synthetic personal leave request',
        ];
        $this->actingAs($user)->post(route('hr.leave.store'), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('hr.leave.store'), $payload)->assertSessionHasErrors('starts_on');
        $leave = EmployeeLeaveRequest::sole();
        $this->actingAs($user)->post(route('hr.leave.decide', $leave), ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($user)->post(route('hr.leave.decide', $leave), ['decision' => 'rejected'])->assertSessionHasErrors('decision');
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame('100.0000', $employee->fresh()->monthly_salary);
        $this->assertSame(1, EmployeeLeaveRequest::count());
        $this->actingAs($user)->post(route('hr.payroll.store'), [
            'month' => now()->format('Y-m'), 'currency_id' => $currency->id,
        ])->assertRedirect();
        $this->actingAs($user)->get(route('hr.payroll.show', PayrollPeriod::sole()))
            ->assertOk()->assertSee('Approved leave:')->assertSee($payload['starts_on'])->assertSee($payload['ends_on']);
    }

    public function test_leave_review_requires_hr_access(): void
    {
        $this->actingAs(User::factory()->create())->get(route('workspace.page', ['hr', 'leave']))->assertForbidden();
    }
}
