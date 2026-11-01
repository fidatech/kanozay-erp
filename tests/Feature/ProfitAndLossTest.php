<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProfitAndLossTest extends TestCase
{
    use RefreshDatabase;

    private function allowedUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        return $user;
    }

    private function addJournal(User $user, Currency $currency, string $number, string $date, string $status, LedgerAccount $account, string $debit, string $credit): void
    {
        $entry = JournalEntry::create([
            'number' => $number, 'entry_date' => $date, 'description' => $number,
            'status' => $status, 'currency_id' => $currency->id, 'created_by' => $user->id,
        ]);
        $entry->lines()->create(['ledger_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
    }

    public function test_report_uses_posted_period_activity_exact_decimals_and_separate_currencies(): void
    {
        $user = $this->allowedUser();
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true, 'is_base' => false]);
        $income = LedgerAccount::create(['code' => '4100', 'name' => 'Sales', 'type' => 'income', 'is_active' => true, 'is_group' => false]);
        $expense = LedgerAccount::create(['code' => '5100', 'name' => 'Utilities', 'type' => 'expense', 'is_active' => true, 'is_group' => false]);
        $this->addJournal($user, $afn, 'PL-1', '2026-10-10', 'posted', $income, '0', '200.1250');
        $this->addJournal($user, $afn, 'PL-2', '2026-10-12', 'posted', $expense, '50.1001', '0');
        $this->addJournal($user, $afn, 'PL-3', '2026-11-01', 'posted', $income, '0', '25');
        $this->addJournal($user, $afn, 'PL-4', '2026-10-11', 'draft', $expense, '30', '0');
        $this->addJournal($user, $usd, 'PL-5', '2026-10-10', 'posted', $income, '0', '60');

        $response = $this->actingAs($user)->get(route('finance.profit-and-loss.index', ['from' => '2026-10-01', 'to' => '2026-10-31']));
        $response->assertOk()->assertSee('AFN Income Statement')->assertSee('USD Income Statement')
            ->assertSee('200.125')->assertSee('50.1001')->assertSee('150.0249')->assertDontSee('80.1001');

        $this->actingAs($user)->get(route('finance.profit-and-loss.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'currency_id' => $afn->id]))
            ->assertOk()->assertSee('AFN Income Statement')->assertDontSee('USD Income Statement');
    }

    public function test_csv_export_includes_currency_separated_totals(): void
    {
        $user = $this->allowedUser();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $income = LedgerAccount::create(['code' => '4100', 'name' => 'Sales', 'type' => 'income', 'is_active' => true, 'is_group' => false]);
        $this->addJournal($user, $currency, 'PL-CSV', '2026-10-10', 'posted', $income, '0', '0.3003');

        $response = $this->actingAs($user)->get(route('finance.profit-and-loss.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'csv']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('0.3003', $response->streamedContent());
    }

    public function test_report_rejects_unprivileged_users_and_invalid_date_ranges(): void
    {
        $this->actingAs(User::factory()->create())->get(route('finance.profit-and-loss.index'))->assertForbidden();
        $user = $this->allowedUser();
        $this->actingAs($user)->get(route('finance.profit-and-loss.index', ['from' => '2026-11-01', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }
}
