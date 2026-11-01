<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        return $user;
    }

    public function test_dashboard_uses_actual_permission_scoped_records_and_hides_unavailable_financial_metrics(): void
    {
        $user = $this->userWith('administration.view');
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        EmployeeProfile::create([
            'employee_number' => 'UAT-EMP-001', 'full_name' => 'Synthetic Employee', 'father_name' => 'Synthetic Parent',
            'gender' => 'Not specified', 'position' => 'UAT', 'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        JournalEntry::create(['number' => 'DB-JE-1', 'entry_date' => today(), 'description' => 'Synthetic posted journal', 'status' => 'posted', 'currency_id' => $currency->id, 'created_by' => $user->id]);
        JournalEntry::create(['number' => 'DB-JE-2', 'entry_date' => today(), 'description' => 'Synthetic draft journal', 'status' => 'draft', 'currency_id' => $currency->id, 'created_by' => $user->id]);
        JournalEntry::create(['number' => 'DB-JE-3', 'entry_date' => today()->subDays(40), 'description' => 'Outside selected period', 'status' => 'posted', 'currency_id' => $currency->id, 'created_by' => $user->id]);

        $this->actingAs($user)->get(route('dashboard', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()->assertSee('Active employee profiles')->assertSee('Posted journals')->assertSee('Synthetic posted journal')
            ->assertDontSee('Synthetic draft journal')->assertDontSee('Outside selected period')
            ->assertDontSee('Total Revenue')->assertDontSee('Net Profit')->assertDontSee('Inventory Value');
    }

    public function test_dashboard_does_not_show_finance_metrics_to_a_user_without_finance_access(): void
    {
        $user = $this->userWith('manufacturing.view');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Production plans')->assertDontSee('Posted journals')->assertDontSee('Total Revenue');
    }

    public function test_dashboard_rejects_an_inverted_date_range(): void
    {
        $user = $this->userWith('administration.view');
        $this->actingAs($user)->get(route('dashboard', ['from' => '2026-11-02', 'to' => '2026-11-01']))
            ->assertSessionHasErrors('to');
    }
}
