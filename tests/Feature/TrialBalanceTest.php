<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function permittedUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        return $user;
    }

    public function test_trial_balance_uses_posted_entries_through_as_of_date_and_keeps_currency_separate(): void
    {
        $user = $this->permittedUser();
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true, 'is_base' => false]);
        $cash = LedgerAccount::create(['code' => '1111', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
        $sales = LedgerAccount::create(['code' => '4100', 'name' => 'Sales', 'type' => 'income', 'is_active' => true, 'is_group' => false]);

        $entry = JournalEntry::create(['number' => 'TB-1', 'entry_date' => '2026-10-20', 'description' => 'Posted sale', 'status' => 'posted', 'currency_id' => $afn->id, 'created_by' => $user->id]);
        $entry->lines()->createMany([
            ['ledger_account_id' => $cash->id, 'debit' => '125.1250', 'credit' => '0'],
            ['ledger_account_id' => $sales->id, 'debit' => '0', 'credit' => '125.1250'],
        ]);
        $draft = JournalEntry::create(['number' => 'TB-2', 'entry_date' => '2026-10-20', 'description' => 'Draft', 'status' => 'draft', 'currency_id' => $afn->id, 'created_by' => $user->id]);
        $draft->lines()->create(['ledger_account_id' => $cash->id, 'debit' => '999', 'credit' => '0']);
        $future = JournalEntry::create(['number' => 'TB-3', 'entry_date' => '2026-10-22', 'description' => 'Future', 'status' => 'posted', 'currency_id' => $afn->id, 'created_by' => $user->id]);
        $future->lines()->create(['ledger_account_id' => $cash->id, 'debit' => '50', 'credit' => '0']);
        $otherCurrency = JournalEntry::create(['number' => 'TB-4', 'entry_date' => '2026-10-20', 'description' => 'USD balance', 'status' => 'posted', 'currency_id' => $usd->id, 'created_by' => $user->id]);
        $otherCurrency->lines()->create(['ledger_account_id' => $cash->id, 'debit' => '20', 'credit' => '0']);

        $response = $this->actingAs($user)->get(route('finance.trial-balance.index', ['as_of' => '2026-10-21']));
        $response->assertOk()->assertSee('125.125')->assertSee('AFN')->assertSee('USD')->assertDontSee('999')->assertDontSee('175.125');
        $this->actingAs($user)->get(route('finance.trial-balance.index', ['as_of' => '2026-10-21', 'currency_id' => $afn->id]))
            ->assertOk()->assertSee('125.125')->assertDontSee('USD');
    }

    public function test_csv_export_is_filtered_to_the_requested_as_of_date(): void
    {
        $user = $this->permittedUser();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $account = LedgerAccount::create(['code' => '1111', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
        $entry = JournalEntry::create(['number' => 'TB-CSV', 'entry_date' => '2026-10-20', 'description' => 'CSV row', 'status' => 'posted', 'currency_id' => $currency->id, 'created_by' => $user->id]);
        $entry->lines()->create(['ledger_account_id' => $account->id, 'debit' => '0.3003', 'credit' => '0']);

        $response = $this->actingAs($user)->get(route('finance.trial-balance.index', ['as_of' => '2026-10-21', 'export' => 'csv']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('0.3003', $response->streamedContent());
    }

    public function test_trial_balance_requires_finance_view_permission(): void
    {
        $this->actingAs(User::factory()->create())->get(route('finance.trial-balance.index'))->assertForbidden();
    }
}
