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

    /** Payroll enforces three distinct people: preparer, expense approver and cash executor. */
    private function officer(): User
    {
        $officer = User::factory()->create();
        $officer->givePermissionTo(['hr.view', 'hr.create', 'hr.update', 'finance.update']);

        return $officer;
    }

    private function review(User $reviewer, PayrollPeriod $period, $line): void
    {
        $this->actingAs($reviewer)->post(route('hr.payroll.lines.review', [$period, $line]), [
            'reference' => 'SYN-PAYROLL-REVIEW', 'review_notes' => 'Synthetic attendance and salary evidence reviewed.',
            'confirm_evidence' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_draft_snapshot_approval_and_payment_balance_without_duplicate_posting(): void
    {
        [$user, $currency, $employee, $cash] = $this->setupPayroll();
        // Salary expense may only be recognized once the salary month has ended.
        $month = now()->subMonthNoOverflow()->format('Y-m');
        $approver = $this->officer();
        $cashier = $this->officer();
        $this->actingAs($user)->post(route('hr.payroll.store'), ['month' => $month, 'currency_id' => $currency->id])->assertRedirect();
        $period = PayrollPeriod::with('lines')->firstOrFail();
        $line = $period->lines->sole();
        $employee->update(['monthly_salary' => '180.0000']);
        $this->assertSame('120.0000', $line->salary_snapshot);

        $this->actingAs($user)->patch(route('hr.payroll.lines.update', [$period, $line]), [
            'payable_amount' => '110.0000', 'adjustment_reason' => 'Synthetic reviewed attendance adjustment',
        ])->assertRedirect();
        $approval = [
            'expense_ledger_id' => LedgerAccount::where('code', '5410')->value('id'),
            'payable_ledger_id' => LedgerAccount::where('code', '2131.01')->value('id'),
            'confirm_review' => '1',
        ];
        // Unreviewed lines block approval, and the preparer can never approve their own draft.
        $this->actingAs($approver)->post(route('hr.payroll.approve', $period), $approval)->assertSessionHasErrors('payroll');
        $this->review($approver, $period, $line);
        $this->actingAs($user)->post(route('hr.payroll.approve', $period), $approval)->assertSessionHasErrors('payroll');
        $this->assertSame('draft', $period->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
        $this->actingAs($approver)->post(route('hr.payroll.approve', $period), $approval)->assertRedirect()->assertSessionHasNoErrors();
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
        // Neither the preparer nor the approver may execute the cash payment.
        $this->actingAs($user)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertSessionHasErrors('payroll');
        $this->actingAs($approver)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertSessionHasErrors('payroll');
        $this->assertSame(0, MoneyTransaction::count());
        $this->actingAs($cashier)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($cashier)->post(route('hr.payroll.lines.pay', [$period, $line]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $period->fresh()->status);
        $this->assertSame(1, MoneyTransaction::count());
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame('posted', MoneyTransaction::sole()->journal->status);
        $this->actingAs($user)->get(route('hr.payroll.lines.payslip', [$period, $line]))
            ->assertOk()->assertSee('Kanozay Foods Manufacturing logo')->assertSee('110.0000')->assertSee('Paid');
    }

    public function test_payment_currency_mismatch_rolls_back_and_approval_needs_finance_permission(): void
    {
        [$user, $currency, $employee] = $this->setupPayroll();
        $other = Currency::create(['code' => 'USD', 'name' => 'Synthetic Dollar', 'is_active' => true]);
        $wrongCash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Other currency cash', 'currency_id' => $other->id, 'created_by' => $user->id]);
        $approver = $this->officer();
        $cashier = $this->officer();
        $this->actingAs($user)->post(route('hr.payroll.store'), ['month' => now()->subMonthNoOverflow()->format('Y-m'), 'currency_id' => $currency->id])->assertRedirect();
        $period = PayrollPeriod::with('lines')->firstOrFail();
        $line = $period->lines->sole();
        $user->revokePermissionTo('finance.update');
        $this->actingAs($user)->post(route('hr.payroll.approve', $period), [])->assertForbidden();
        $user->givePermissionTo('finance.update');
        $this->review($approver, $period, $line);
        $this->actingAs($approver)->post(route('hr.payroll.approve', $period), [
            'expense_ledger_id' => LedgerAccount::where('code', '5410')->value('id'),
            'payable_ledger_id' => LedgerAccount::where('code', '2131.01')->value('id'),
            'confirm_review' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($cashier)->post(route('hr.payroll.lines.pay', [$period, $line]), [
            'source_account_id' => $wrongCash->id, 'payment_date' => now()->toDateString(), 'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasErrors('payroll');
        $this->assertSame(0, MoneyTransaction::count());
        $this->assertSame(1, JournalEntry::count());
    }
}
