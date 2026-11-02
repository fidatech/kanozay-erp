<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeProfile;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_record_correction_and_payroll_review_do_not_change_salary(): void
    {
        $user = User::factory()->create();
        foreach (['hr.view', 'hr.create', 'hr.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.view', 'hr.create', 'hr.update']);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-ATT-01', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $payload = ['employee_profile_id' => $employee->id, 'attendance_date' => now()->toDateString(), 'status' => 'present'];
        $this->actingAs($user)->post(route('hr.attendance.store'), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('hr.attendance.store'), $payload)->assertSessionHasErrors('attendance_date');
        $entry = EmployeeAttendance::sole();
        $this->actingAs($user)->patch(route('hr.attendance.correct', $entry), [
            'status' => 'leave', 'correction_reason' => 'Synthetic approved correction',
        ])->assertRedirect();
        $this->assertSame('leave', $entry->fresh()->status);
        $this->assertSame(1, EmployeeAttendance::count());
        $this->assertSame(1, ActivityLog::where('action', 'hr.attendance.corrected')->count());

        $this->actingAs($user)->post(route('hr.payroll.store'), ['month' => now()->format('Y-m'), 'currency_id' => $currency->id])->assertRedirect();
        $period = PayrollPeriod::with('lines')->sole();
        $this->assertSame('100.0000', $period->lines->sole()->payable_amount);
        $this->actingAs($user)->get(route('hr.payroll.show', $period))->assertOk()->assertSee('Leave: 1');
    }

    public function test_attendance_requires_hr_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('workspace.page', ['hr', 'attendance']))->assertForbidden();
        $this->actingAs($user)->post(route('hr.attendance.store'), [])->assertForbidden();
    }
}
