<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollPeriodWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupPayroll(): array
    {
        $user = User::factory()->create();
        foreach (['hr.view', 'hr.create', 'hr.update', 'finance.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['hr.view', 'hr.create', 'hr.update', 'finance.update']);
        $this->seed(ChartOfAccountsSeeder::class);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true, 'is_base' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-001', 'full_name' => 'Synthetic Employee',
            'monthly_salary' => '120.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic payroll cash', 'currency_id' => $currency->id, 'created_by' => $user->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox', $cash->id, $cash->name, $currency->id);
        return [$user, $currency, $employee, $cash];
    }

    public function test_draft_snapshot_approval_and_payment_balance_without_duplicate_posting(): void
    {
        [$user, $currency, $employee, $cash] = $this->setupPayroll();
        $month = now()->format('Y-m');
        $this->actingAs($user)->post(route('hr.payroll.store'), ['month' => $month, 'currency_id' => $currency->id])->assertRedirect();
        $period = PayrollPeriod::with('lines')->firstOrFail();
        $line = $period->lines->sole();
        $employee->update(['monthly_salary' => '180.0000']);
        $this->assertSame('120.0000', $line->salary_snapshot);

        $this->actingAs($user)->patch(route('hr.payroll.lines.update', [$period, $line]), [
            'payable_amount' => '110.0000', 'adjustment_reason' => 'Synthetic reviewed attendance adjustment',
        ])->assertRedirect();
        $this->actingAs($user)->post(route('hr.payroll.approve', $period), [
            'expense_ledger_id' => LedgerAccount::where('code', '5410')->value('id'),
            'payable_ledger_id' => LedgerAccount::where('code', '2131')->value('id'),
            'confirm_review' => '1',
        ])->assertRedirect();
        $period->refresh();
        $this->assertSame('approved', $period->status);
        $journal = $period->approvalJournal;
        $this->assertSame('posted', $journal->status);
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();
        foreach ($journal->lines as $entry) {
            $debits = $debits->plus((string) $entry->debit);
            $credits = $credits->plus((string) $entry->credit);
        }
        $this->assertSame('110.0000', (string) $debits->toScale(4));
        $this->assertSame('110.0000', (string) $credits->toScale(4));

        $token = (string) Str::uuid();
        $payload = ['source_account_id' => $cash->id, 'payment_date' => now()->toDateString(), 'submission_token' => $token];
        $this->actingAs($user)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertRedirect();
        $this->assertSame('paid', $period->fresh()->status);
        $this->assertSame(1, MoneyTransaction::count());
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame('posted', MoneyTransaction::sole()->journal->status);
    }

    public function test_payment_currency_mismatch_rolls_back_and_approval_needs_finance_permission(): void
    {
        [$user, $currency, $employee] = $this->setupPayroll();
        $other = Currency::create(['code' => 'USD', 'name' => 'Synthetic Dollar', 'is_active' => true]);
        $wrongCash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Other currency cash', 'currency_id' => $other->id, 'created_by' => $user->id]);
        $this->actingAs($user)->post(route('hr.payroll.store'), ['month' => now()->format('Y-m'), 'currency_id' => $currency->id])->assertRedirect();
        $period = PayrollPeriod::with('lines')->firstOrFail();
        $line = $period->lines->sole();
        $user->revokePermissionTo('finance.update');
        $this->actingAs($user)->post(route('hr.payroll.approve', $period), [])->assertForbidden();
        $user->givePermissionTo('finance.update');
        $this->actingAs($user)->post(route('hr.payroll.approve', $period), [
            'expense_ledger_id' => LedgerAccount::where('code', '5410')->value('id'),
            'payable_ledger_id' => LedgerAccount::where('code', '2131')->value('id'),
            'confirm_review' => '1',
        ])->assertRedirect();
        $this->actingAs($user)->post(route('hr.payroll.lines.pay', [$period, $line]), [
            'source_account_id' => $wrongCash->id, 'payment_date' => now()->toDateString(), 'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasErrors('payroll');
        $this->assertSame(0, MoneyTransaction::count());
        $this->assertSame(1, JournalEntry::count());
    }
}
