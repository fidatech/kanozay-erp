<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\EmployeeSalaryAdvance;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeSalaryAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_advance_posts_balanced_receivable_and_cash_once_without_changing_payroll(): void
    {
        $user = User::factory()->create();
        foreach (['hr.view', 'hr.update', 'finance.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.view', 'hr.update', 'finance.update']);
        $this->seed(ChartOfAccountsSeeder::class);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-ADV-01', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic cash', 'currency_id' => $currency->id, 'created_by' => $user->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox', $cash->id, $cash->name, $currency->id);
        $receivable = LedgerAccount::create(['code' => 'SYN-ADV', 'name' => 'Synthetic staff advance receivable', 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
        $payload = [
            'employee_profile_id' => $employee->id, 'source_account_id' => $cash->id,
            'receivable_ledger_id' => $receivable->id, 'issued_on' => now()->toDateString(),
            'amount' => '25.5000', 'reason' => 'Synthetic approved employee advance',
            'submission_token' => (string) Str::uuid(),
        ];
        $this->actingAs($user)->post(route('hr.advances.store'), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('hr.advances.store'), $payload)->assertRedirect();
        $this->assertSame(1, EmployeeSalaryAdvance::count());
        $this->assertSame(1, MoneyTransaction::count());
        $this->assertSame(1, JournalEntry::count());
        $journal = JournalEntry::with('lines')->sole();
        $this->assertSame('posted', $journal->status);
        $this->assertSame(0, BigDecimal::of((string) $journal->lines->sum('debit'))->compareTo(BigDecimal::of((string) $journal->lines->sum('credit'))));
        $this->assertSame('100.0000', $employee->fresh()->monthly_salary);
        $this->actingAs($user)->get(route('hr.advances.index'))->assertOk()->assertSee('SYN-ADV-01');
        $this->actingAs($user)->post(route('hr.advances.store'), array_merge($payload, ['amount' => '50']))->assertSessionHasErrors('submission_token');

        $advance = EmployeeSalaryAdvance::sole();
        $repayment = ['destination_account_id' => $cash->id, 'received_on' => now()->toDateString(), 'amount' => '10.2500', 'submission_token' => (string) Str::uuid()];
        $this->actingAs($user)->post(route('hr.advances.repay', $advance), $repayment)->assertRedirect();
        $this->actingAs($user)->post(route('hr.advances.repay', $advance), $repayment)->assertRedirect();
        $this->assertSame(1, EmployeeAdvanceRepayment::count());
        $this->actingAs($user)->post(route('hr.advances.repay', $advance), array_merge($repayment, ['amount' => '20', 'submission_token' => (string) Str::uuid()]))->assertSessionHasErrors('amount');
        $this->assertSame(2, JournalEntry::count());
        $this->actingAs($user)->post(route('hr.advances.repay', $advance), array_merge($repayment, ['amount' => '15.25', 'submission_token' => (string) Str::uuid()]))->assertRedirect();
        $this->assertSame(2, EmployeeAdvanceRepayment::count());
        $this->assertSame(3, MoneyTransaction::count());
        $this->assertSame(3, JournalEntry::where('status', 'posted')->count());
        $this->actingAs($user)->get(route('hr.advances.index'))->assertOk()->assertSee('Repaid in full');
    }

    public function test_advance_rejects_wrong_currency_and_requires_dual_permission(): void
    {
        $user = User::factory()->create();
        foreach (['hr.update', 'finance.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.update', 'finance.update']);
        $employeeCurrency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $other = Currency::create(['code' => 'USD', 'name' => 'Synthetic Dollar', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-ADV-02', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $employeeCurrency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Wrong currency cash', 'currency_id' => $other->id, 'created_by' => $user->id]);
        $ledger = LedgerAccount::create(['code' => 'SYN-RECV', 'name' => 'Synthetic receivable', 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
        $payload = ['employee_profile_id' => $employee->id, 'source_account_id' => $cash->id, 'receivable_ledger_id' => $ledger->id, 'issued_on' => now()->toDateString(), 'amount' => '20', 'reason' => 'Synthetic advance request', 'submission_token' => (string) Str::uuid()];
        $user->revokePermissionTo('finance.update');
        $this->actingAs($user)->post(route('hr.advances.store'), $payload)->assertForbidden();
        $user->givePermissionTo('finance.update');
        $this->actingAs($user)->post(route('hr.advances.store'), $payload)->assertSessionHasErrors('source_account_id');
        $this->assertSame(0, EmployeeSalaryAdvance::count());
        $this->assertSame(0, JournalEntry::count());
    }
}
