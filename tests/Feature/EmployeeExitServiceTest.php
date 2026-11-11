<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\OperationalRegister;
use App\Models\User;
use App\Services\EmployeeExitService;
use App\Services\PayrollPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * M21: an employee is deactivated only by closing an approved separation record that has
 * finance and asset clearance, and never while draft payroll still includes them.
 */
class EmployeeExitServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = User::factory()->create();
        $this->currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
    }

    private function employee(string $number, ?string $hiredOn = null): EmployeeProfile
    {
        return EmployeeProfile::create([
            'employee_number' => $number, 'full_name' => 'Synthetic leaver '.$number, 'monthly_salary' => '15000.0000',
            'salary_currency_id' => $this->currency->id, 'is_active' => true, 'hired_on' => $hiredOn, 'created_by' => $this->hr->id,
        ]);
    }

    private function separation(EmployeeProfile $employee, array $data = [], string $status = 'approved'): OperationalRegister
    {
        return OperationalRegister::create([
            'workflow' => 'hr.exits', 'number' => 'EXIT-'.Str::upper(Str::random(8)), 'submission_token' => (string) Str::uuid(),
            'status' => $status, 'created_by' => $this->hr->id,
            'data' => $data + [
                'employee' => $employee->id, 'last_working_on' => today()->subDay()->toDateString(), 'reason' => 'Synthetic resignation',
                'asset_clearance' => 'SYN-ASSET-CLEAR', 'finance_clearance' => 'SYN-FIN-CLEAR',
            ],
        ]);
    }

    private function assertRefused(OperationalRegister $record, EmployeeProfile $employee): void
    {
        try {
            app(EmployeeExitService::class)->closeApprovedRecord($record, $this->hr->id);
            $this->fail('The separation should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertTrue((bool) $employee->fresh()->is_active);
        $this->assertNull($employee->fresh()->separated_on);
    }

    public function test_cleared_approved_separation_deactivates_the_employee_once_and_is_audited(): void
    {
        $employee = $this->employee('EXIT-EMP-1', today()->subYear()->toDateString());
        $lastDay = today()->subDay()->toDateString();
        $record = $this->separation($employee);

        app(EmployeeExitService::class)->closeApprovedRecord($record, $this->hr->id);

        $closed = $employee->fresh();
        $this->assertFalse((bool) $closed->is_active);
        $this->assertSame($lastDay, \Carbon\CarbonImmutable::parse($closed->separated_on)->toDateString());
        $this->assertDatabaseHas('activity_logs', ['action' => 'hr.employee.exit.closed', 'subject_id' => $employee->id]);

        // A second separation for the same person is refused.
        try {
            app(EmployeeExitService::class)->closeApprovedRecord($this->separation($employee), $this->hr->id);
            $this->fail('An inactive employee cannot be separated again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'hr.employee.exit.closed')->count());

        // The month in which they still worked keeps their salary line; later months exclude them.
        $worked = app(PayrollPeriodService::class)->create(\Carbon\CarbonImmutable::parse($lastDay)->format('Y-m'), $this->currency->id, $this->hr->id);
        $this->assertSame(1, $worked->lines()->where('employee_profile_id', $employee->id)->count());
        try {
            app(PayrollPeriodService::class)->create(\Carbon\CarbonImmutable::parse($lastDay)->addMonthNoOverflow()->format('Y-m'), $this->currency->id, $this->hr->id);
            $this->fail('Payroll after the separation month must not include the separated employee.');
        } catch (ValidationException $exception) {
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('payroll_lines')->count());
        }
    }

    public function test_separation_is_refused_without_approval_clearance_or_valid_dates(): void
    {
        $employee = $this->employee('EXIT-EMP-2', today()->subMonths(2)->toDateString());

        $this->assertRefused($this->separation($employee, [], 'submitted'), $employee);
        $this->assertRefused($this->separation($employee, ['asset_clearance' => '']), $employee);
        $this->assertRefused($this->separation($employee, ['finance_clearance' => '']), $employee);
        $this->assertRefused($this->separation($employee, ['last_working_on' => today()->addDays(3)->toDateString()]), $employee);
        $this->assertRefused($this->separation($employee, ['last_working_on' => today()->subMonths(3)->toDateString()]), $employee);
    }

    public function test_employee_in_draft_payroll_cannot_be_separated_until_payroll_is_resolved(): void
    {
        $employee = $this->employee('EXIT-EMP-3', today()->subYear()->toDateString());
        $this->actingAs($this->hr);
        $period = app(PayrollPeriodService::class)->create(today()->subMonthNoOverflow()->format('Y-m'), $this->currency->id, $this->hr->id);
        $this->assertSame(1, $period->lines()->where('employee_profile_id', $employee->id)->count());

        $this->assertRefused($this->separation($employee), $employee);
    }
}
