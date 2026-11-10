<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\BankReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * D07 acceptance: statement period, CSV import and re-import, exact matching to posted
 * bank GL movements, independent approval, immutability and later staleness detection.
 */
class BankReconciliationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW = 'Synthetic statement agreed line by line to posted bank ledger movements.';

    /** @return array{0:User,1:User,2:FinancialAccount,3:FinancialAccount} */
    private function fixture(): array
    {
        foreach (['finance.view', 'finance.update', 'administration.view', 'administration.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        foreach ([$maker, $checker] as $user) {
            $user->givePermissionTo(['finance.view', 'finance.update', 'administration.view', 'administration.update']);
        }
        $this->seed(ChartOfAccountsSeeder::class);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic cash', 'currency_id' => $currency->id, 'is_active' => true, 'created_by' => $maker->id]);
        $bank = FinancialAccount::create(['type' => 'bank', 'name' => 'Synthetic bank', 'currency_id' => $currency->id, 'is_active' => true, 'created_by' => $maker->id]);
        $provisioner = app(EntityAccountProvisioner::class);
        $provisioner->provision('cashbox', $cash->id, $cash->name, $currency->id);
        $provisioner->provision('bank', $bank->id, $bank->name, $currency->id);

        return [$maker, $checker, $cash, $bank];
    }

    private function transfer(User $user, FinancialAccount $from, FinancialAccount $to, string $amount, string $date): MoneyTransaction
    {
        $token = (string) Str::uuid();
        $this->actingAs($user)->post(route('finance.money.store'), [
            'submission_token' => $token, 'type' => 'transfer', 'transaction_date' => $date,
            'source_account_id' => $from->id, 'destination_account_id' => $to->id,
            'amount' => $amount, 'description' => 'Synthetic bank movement',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return MoneyTransaction::where('submission_token', $token)->firstOrFail();
    }

    private function openPeriod(User $user, FinancialAccount $bank, string $closing): int
    {
        $this->actingAs($user)->post(route('finance.bank-reconciliations.store'), [
            'financial_account_id' => $bank->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'statement_opening' => '0', 'statement_closing' => $closing, 'statement_reference' => 'SYN-STMT-2026-10',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return (int) DB::table('bank_reconciliation_periods')->where('financial_account_id', $bank->id)->value('id');
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.csv', "date,direction,amount,reference,description\n".$body);
    }

    public function test_statement_import_matching_and_independent_approval_reconcile_to_the_bank_ledger(): void
    {
        [$maker, $checker, $cash, $bank] = $this->fixture();
        $deposit = $this->transfer($maker, $cash, $bank, '500.0000', '2026-10-05');
        $withdrawal = $this->transfer($maker, $bank, $cash, '120.5000', '2026-10-12');
        $periodId = $this->openPeriod($maker, $bank, '379.5000');
        $period = DB::table('bank_reconciliation_periods')->where('id', $periodId)->first();
        $this->assertSame('BREC-'.str_pad((string) $periodId, 7, '0', STR_PAD_LEFT), $period->number);
        $this->assertSame('draft', $period->status);

        // A second statement for overlapping dates of the same account is refused.
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.store'), [
            'financial_account_id' => $bank->id, 'period_start' => '2026-10-15', 'period_end' => '2026-10-20',
            'statement_opening' => '0', 'statement_closing' => '0', 'statement_reference' => 'SYN-OVERLAP',
        ])->assertSessionHasErrors('period_start');
        $this->assertDatabaseCount('bank_reconciliation_periods', 1);

        // Import, then import the identical file again: no duplicate statement rows.
        $rows = "2026-10-05,deposit,500.00,BNK-0001,Cash deposit\n2026-10-12,withdrawal,120.50,BNK-0002,Cash withdrawal\n";
        foreach ([1, 2] as $attempt) {
            $this->actingAs($maker)->post(route('finance.bank-reconciliations.import', $periodId), [
                'csv' => $this->csv($rows), 'confirm_source_csv' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseCount('bank_statement_entries', 2);
        }
        // A row outside the statement period rejects the whole file.
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.import', $periodId), [
            'csv' => $this->csv("2026-10-20,deposit,10.00,BNK-0003,In period\n2026-11-02,deposit,10.00,BNK-0004,Outside\n"),
            'confirm_source_csv' => '1',
        ])->assertSessionHasErrors('csv');
        $this->assertDatabaseCount('bank_statement_entries', 2);
        // Importing never posts to the ledger or matches anything by itself.
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('bank_statement_matches', 0);

        $depositLine = DB::table('bank_statement_entries')->where('bank_reference', 'BNK-0001')->value('id');
        $withdrawalLine = DB::table('bank_statement_entries')->where('bank_reference', 'BNK-0002')->value('id');
        $match = fn (User $user, int $line, int $money) => $this->actingAs($user)->post(
            route('finance.bank-reconciliations.lines.match', [$periodId, $line]), ['money_transaction_id' => $money]);

        // Wrong direction/date/amount cannot be matched; the right movement can, once.
        $match($maker, $depositLine, $withdrawal->id)->assertSessionHasErrors('money_transaction_id');
        $match($maker, $depositLine, $deposit->id)->assertRedirect()->assertSessionHasNoErrors();
        $match($maker, $depositLine, $deposit->id)->assertSessionHasErrors('money_transaction_id');
        $this->assertDatabaseCount('bank_statement_matches', 1);

        $approval = ['review_notes' => self::REVIEW, 'confirm_all_source_lines' => '1', 'confirm_original_gl' => '1'];
        // Not while a statement line is unmatched.
        $this->actingAs($checker)->post(route('finance.bank-reconciliations.approve', $periodId), $approval)->assertSessionHasErrors('status');
        $match($maker, $withdrawalLine, $withdrawal->id)->assertRedirect()->assertSessionHasNoErrors();
        // Not by the person who prepared the reconciliation.
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.approve', $periodId), $approval)->assertSessionHasErrors('status');
        $this->assertSame('draft', DB::table('bank_reconciliation_periods')->where('id', $periodId)->value('status'));
        $this->actingAs($checker)->post(route('finance.bank-reconciliations.approve', $periodId), $approval)
            ->assertRedirect()->assertSessionHasNoErrors();

        $approved = DB::table('bank_reconciliation_periods')->where('id', $periodId)->first();
        $this->assertSame('approved', $approved->status);
        $this->assertSame($checker->id, (int) $approved->approved_by);
        $this->assertTrue(app(BankReconciliationService::class)->auditApproved($periodId));
        // Reconciling created no accounting entries of its own.
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('money_transactions', 2);

        // An approved statement is immutable.
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.lines.store', $periodId), [
            'posted_on' => '2026-10-20', 'direction' => 'deposit', 'amount' => '1.00', 'bank_reference' => 'BNK-LATE',
            'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasErrors('statement');
        $this->actingAs($checker)->post(route('finance.bank-reconciliations.approve', $periodId), $approval)->assertSessionHasErrors('status');
        $this->assertDatabaseCount('bank_statement_entries', 2);

        $this->actingAs($checker)->get(route('finance.bank-reconciliations.index'))->assertOk()->assertSee($approved->number);
        $this->actingAs($checker)->get(route('finance.bank-reconciliations.show', $periodId))->assertOk()->assertSee('BNK-0001')->assertSee('BNK-0002');

        // A bank movement posted into the signed period afterwards makes the approval stale.
        $this->transfer($maker, $cash, $bank, '5.0000', '2026-10-25');
        $this->assertFalse(app(BankReconciliationService::class)->auditApproved($periodId));
    }

    public function test_statement_that_disagrees_with_the_bank_ledger_cannot_be_approved(): void
    {
        [$maker, $checker, $cash, $bank] = $this->fixture();
        $deposit = $this->transfer($maker, $cash, $bank, '500.0000', '2026-10-05');
        // A second posted bank movement that the statement does not show.
        $this->transfer($maker, $cash, $bank, '40.0000', '2026-10-09');
        $periodId = $this->openPeriod($maker, $bank, '500.0000');
        $token = (string) Str::uuid();
        $line = ['posted_on' => '2026-10-05', 'direction' => 'deposit', 'amount' => '500.00', 'bank_reference' => 'BNK-0001', 'submission_token' => $token];
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.lines.store', $periodId), $line)->assertRedirect()->assertSessionHasNoErrors();
        // Replaying the same form does not add a second line; reusing the token for another line is refused.
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.lines.store', $periodId), $line)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.lines.store', $periodId), ['bank_reference' => 'BNK-OTHER'] + $line)
            ->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('bank_statement_entries', 1);
        $lineId = (int) DB::table('bank_statement_entries')->value('id');
        $this->actingAs($maker)->post(route('finance.bank-reconciliations.lines.match', [$periodId, $lineId]), ['money_transaction_id' => $deposit->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        // Statement arithmetic agrees (0 + 500 = 500) but the ledger holds 540 and one movement is unmatched.
        $this->actingAs($checker)->post(route('finance.bank-reconciliations.approve', $periodId), [
            'review_notes' => self::REVIEW, 'confirm_all_source_lines' => '1', 'confirm_original_gl' => '1',
        ])->assertSessionHasErrors('status');
        $this->assertSame('draft', DB::table('bank_reconciliation_periods')->where('id', $periodId)->value('status'));
        $this->assertFalse(app(BankReconciliationService::class)->auditApproved($periodId));
    }

    public function test_bank_reconciliation_requires_finance_permissions(): void
    {
        [$maker, , , $bank] = $this->fixture();
        $periodId = $this->openPeriod($maker, $bank, '0');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('finance.bank-reconciliations.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('finance.bank-reconciliations.show', $periodId))->assertForbidden();
        $this->actingAs($outsider)->post(route('finance.bank-reconciliations.store'), [])->assertForbidden();
        $this->actingAs($outsider)->post(route('finance.bank-reconciliations.import', $periodId), [])->assertForbidden();
        $this->actingAs($outsider)->post(route('finance.bank-reconciliations.approve', $periodId), [])->assertForbidden();
        // Read-only finance users can view but not change.
        $outsider->givePermissionTo('finance.view');
        $this->actingAs($outsider)->get(route('finance.bank-reconciliations.show', $periodId))->assertOk();
        $this->actingAs($outsider)->post(route('finance.bank-reconciliations.lines.store', $periodId), [])->assertForbidden();
        $this->assertDatabaseCount('bank_statement_entries', 0);
    }
}
