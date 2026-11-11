<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\PayrollPeriodService;
use App\Services\PayrollTaxPolicyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\CapitalizesProductionBatches;
use Tests\TestCase;

/**
 * D08: withheld payroll tax is paid to the authority only from the approved accrual,
 * in parts if needed, never beyond the amount withheld, and with proposer, reviewer and
 * payer all different from each other and from the payroll and policy approvers.
 *
 * The tax brackets are synthetic test data, not Afghan statutory rules.
 */
class PayrollTaxRemittanceWorkflowTest extends TestCase
{
    use CapitalizesProductionBatches; // only for ledgerBalanceForTest()
    use RefreshDatabase;

    private const NOTES = 'Synthetic withholding agreed to the approved payroll accrual and the applied tax policy for the month.';

    public function test_withheld_tax_is_remitted_in_parts_with_three_way_segregation_and_cannot_exceed_the_liability(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $permissions = ['hr.view', 'hr.create', 'hr.update', 'finance.update', 'finance.view', 'administration.view', 'administration.update'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        [$maker, $checker, $proposer, $reviewer, $payer] = User::factory()->count(5)->create()->all();
        $maker->givePermissionTo($permissions);
        foreach ([$checker, $proposer, $reviewer, $payer] as $user) {
            $user->givePermissionTo(['hr.view', 'hr.update', 'finance.update', 'finance.view']);
        }

        // --- Source: one employee, an approved synthetic policy, an approved payroll month.
        $this->actingAs($maker);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        EmployeeProfile::create([
            'employee_number' => 'REMIT-EMP-1', 'full_name' => 'Synthetic Remittance Employee', 'monthly_salary' => '20000.0000',
            'salary_currency_id' => $currency->id, 'is_active' => true, 'created_by' => $maker->id,
        ]);
        $liability = LedgerAccount::create(['code' => 'TAX-WH-REMIT', 'name' => 'Synthetic withholding liability', 'type' => 'liability', 'is_active' => true, 'is_group' => false]);
        $month = today()->subMonthNoOverflow();
        $policy = app(PayrollTaxPolicyService::class)->create([
            'code' => 'REMIT-TAX-AFN', 'name' => 'Synthetic withholding policy', 'currency_id' => $currency->id,
            'effective_from' => $month->copy()->startOfMonth()->toDateString(), 'basis' => 'base_salary',
            'liability_ledger_id' => $liability->id, 'notes' => 'Synthetic test data, not statutory payroll tax guidance.',
            'brackets' => [
                ['lower_bound' => '0', 'upper_bound' => '10000', 'fixed_tax' => '0', 'marginal_percent' => '0'],
                ['lower_bound' => '10000', 'upper_bound' => null, 'fixed_tax' => '0', 'marginal_percent' => '10'],
            ],
        ], $maker->id);
        $this->actingAs($checker)->post(route('hr.payroll.tax-policies.approve', $policy), ['approval_reference' => 'SYN-POLICY-OK'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($maker);
        $period = app(PayrollPeriodService::class)->create($month->format('Y-m'), $currency->id, $maker->id);
        $this->actingAs($maker)->post(route('hr.payroll.tax-policies.apply', $policy), ['payroll_period_id' => $period->id, 'confirm_review' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $line = $period->lines()->firstOrFail();
        $this->actingAs($checker)->post(route('hr.payroll.lines.review', [$period, $line]), [
            'reference' => 'SYN-LINE-REVIEW', 'review_notes' => 'Synthetic salary and withholding evidence reviewed.', 'confirm_evidence' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($checker)->post(route('hr.payroll.approve', $period), [
            'expense_ledger_id' => LedgerAccount::where('code', '5410')->value('id'),
            'payable_ledger_id' => LedgerAccount::where('code', '2131.01')->value('id'), 'confirm_review' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('-1000.0000', $this->ledgerBalanceForTest($liability->id, $currency->id));

        // --- A funded cash account in the same currency.
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic tax cash', 'currency_id' => $currency->id, 'is_active' => true, 'created_by' => $maker->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox', $cash->id, $cash->name, $currency->id);
        $cashLedger = (int) DB::table('entity_ledger_accounts')->where(['entity_type' => 'cashbox', 'entity_id' => $cash->id, 'purpose' => 'cash'])->value('ledger_account_id');
        $this->actingAs($maker)->post(route('finance.money.store'), [
            'submission_token' => (string) Str::uuid(), 'type' => 'receipt', 'transaction_date' => $month->copy()->endOfMonth()->toDateString(),
            'destination_account_id' => $cash->id, 'counterpart_ledger_account_id' => LedgerAccount::where('code', '4110')->value('id'),
            'amount' => '5000', 'description' => 'Synthetic funding',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('5000.0000', $this->ledgerBalanceForTest($cashLedger, $currency->id));

        $proposal = fn (string $amount, ?string $token = null) => [
            'payroll_period_id' => $period->id, 'payroll_tax_policy_id' => $policy->id, 'financial_account_id' => $cash->id,
            'amount' => $amount, 'remit_on' => today()->toDateString(), 'evidence_reference' => 'SYN-TAX-RECEIPT',
            'source_notes' => self::NOTES, 'submission_token' => $token ?? (string) Str::uuid(), 'confirm_original_withholding' => '1',
        ];
        $review = fn (string $decision) => [
            'decision' => $decision, 'review_reference' => 'SYN-TAX-REVIEW', 'review_notes' => self::NOTES, 'confirm_independent_tax_review' => '1',
        ];
        $confirm = ['confirm_source_liability' => '1', 'confirm_original_payment' => '1'];
        $store = route('finance.payroll-tax-remittances.store');
        $status = fn (int $id) => DB::table('payroll_tax_remittances')->where('id', $id)->value('status');

        // --- Proposal: not by the payroll/policy approver, not above what was withheld.
        $this->actingAs($checker)->post($store, $proposal('400'))->assertSessionHasErrors('tax_remittance');
        $this->actingAs($proposer)->post($store, $proposal('1000.01'))->assertSessionHasErrors('tax_remittance');
        $token = (string) Str::uuid();
        $this->actingAs($proposer)->post($store, $proposal('400', $token))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($proposer)->post($store, $proposal('400', $token))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payroll_tax_remittances', 1);
        $first = (int) DB::table('payroll_tax_remittances')->value('id');
        // Proposing moves no money.
        $this->assertSame('-1000.0000', $this->ledgerBalanceForTest($liability->id, $currency->id));

        // --- Review: independent of proposer and of the payroll source actors.
        foreach ([$proposer, $maker, $checker] as $conflicted) {
            $this->actingAs($conflicted)->post(route('finance.payroll-tax-remittances.review', $first), $review('approve'))
                ->assertSessionHasErrors('tax_remittance');
        }
        // --- Payment cannot happen before approval.
        $this->actingAs($payer)->post(route('finance.payroll-tax-remittances.post', $first), $confirm)->assertSessionHasErrors('tax_remittance');
        $this->actingAs($reviewer)->post(route('finance.payroll-tax-remittances.review', $first), $review('approve'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $status($first));

        // --- Payment: a third person.
        foreach ([$proposer, $reviewer, $checker] as $conflicted) {
            $this->actingAs($conflicted)->post(route('finance.payroll-tax-remittances.post', $first), $confirm)
                ->assertSessionHasErrors('tax_remittance');
        }
        $this->assertSame('5000.0000', $this->ledgerBalanceForTest($cashLedger, $currency->id));
        $this->actingAs($payer)->post(route('finance.payroll-tax-remittances.post', $first), $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('posted', $status($first));
        $this->assertSame('-600.0000', $this->ledgerBalanceForTest($liability->id, $currency->id));
        $this->assertSame('4600.0000', $this->ledgerBalanceForTest($cashLedger, $currency->id));
        // Paying the same remittance again is refused and moves nothing.
        $this->actingAs($payer)->post(route('finance.payroll-tax-remittances.post', $first), $confirm)->assertSessionHasErrors('tax_remittance');
        $this->assertSame('4600.0000', $this->ledgerBalanceForTest($cashLedger, $currency->id));

        // --- Remainder: 700 is too much, 600 is exactly what is left; a rejected proposal frees it again.
        $this->actingAs($proposer)->post($store, $proposal('700'))->assertSessionHasErrors('tax_remittance');
        $this->actingAs($proposer)->post($store, $proposal('600'))->assertRedirect()->assertSessionHasNoErrors();
        $second = (int) DB::table('payroll_tax_remittances')->max('id');
        $this->actingAs($proposer)->post($store, $proposal('1'))->assertSessionHasErrors('tax_remittance');
        $this->actingAs($reviewer)->post(route('finance.payroll-tax-remittances.review', $second), $review('reject'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $status($second));
        $this->actingAs($payer)->post(route('finance.payroll-tax-remittances.post', $second), $confirm)->assertSessionHasErrors('tax_remittance');

        $this->actingAs($proposer)->post($store, $proposal('600'))->assertRedirect()->assertSessionHasNoErrors();
        $third = (int) DB::table('payroll_tax_remittances')->max('id');
        $this->assertNotSame($second, $third);
        $this->actingAs($reviewer)->post(route('finance.payroll-tax-remittances.review', $third), $review('approve'))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($payer)->post(route('finance.payroll-tax-remittances.post', $third), $confirm)->assertRedirect()->assertSessionHasNoErrors();

        // --- Reconciliation: liability fully cleared, cash reduced by exactly the tax withheld.
        $this->assertSame('0.0000', $this->ledgerBalanceForTest($liability->id, $currency->id));
        $this->assertSame('4000.0000', $this->ledgerBalanceForTest($cashLedger, $currency->id));
        $this->assertSame(2, DB::table('payroll_tax_remittances')->where('status', 'posted')->count());
        $this->actingAs($proposer)->post($store, $proposal('0.01'))->assertSessionHasErrors('tax_remittance');

        $this->actingAs($reviewer)->get(route('finance.payroll-tax-remittances.index'))->assertOk()->assertSee('REMIT-TAX-AFN');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('finance.payroll-tax-remittances.index'))->assertForbidden();
        $this->actingAs($outsider)->post($store, $proposal('1'))->assertForbidden();
    }
}
