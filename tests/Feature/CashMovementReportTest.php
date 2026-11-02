<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CashMovementReportTest extends TestCase
{
    use RefreshDatabase;

    private function allowedUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        return $user;
    }

    private function makeFinancialAccount(User $user, Currency $currency, string $type, string $name): array
    {
        $account = FinancialAccount::create(['type' => $type, 'name' => $name, 'currency_id' => $currency->id, 'is_active' => true, 'created_by' => $user->id]);
        $ledger = LedgerAccount::create(['code' => '10' . $account->id, 'name' => $name, 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
        DB::table('entity_ledger_accounts')->insert([
            'entity_type' => $type, 'entity_id' => $account->id, 'currency_id' => $currency->id,
            'purpose' => 'cash', 'ledger_account_id' => $ledger->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$account, $ledger];
    }

    private function createJournalLine(User $user, Currency $currency, LedgerAccount $ledger, string $number, string $date, string $status, string $debit, string $credit): void
    {
        $entry = JournalEntry::create(['number' => $number, 'entry_date' => $date, 'description' => $number, 'status' => $status, 'currency_id' => $currency->id, 'created_by' => $user->id]);
        $entry->lines()->create(['ledger_account_id' => $ledger->id, 'debit' => $debit, 'credit' => $credit]);
    }

    public function test_cash_movement_report_uses_posted_entries_and_keeps_currencies_separate(): void
    {
        $user = $this->allowedUser();
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true, 'is_base' => false]);
        [, $cashLedger] = $this->makeFinancialAccount($user, $afn, 'cashbox', 'Factory cash');
        [, $bankLedger] = $this->makeFinancialAccount($user, $usd, 'bank', 'USD bank');
        $this->createJournalLine($user, $afn, $cashLedger, 'CM-1', '2026-09-30', 'posted', '100', '0');
        $this->createJournalLine($user, $afn, $cashLedger, 'CM-2', '2026-10-10', 'posted', '30', '0');
        $this->createJournalLine($user, $afn, $cashLedger, 'CM-3', '2026-10-15', 'posted', '0', '10');
        $this->createJournalLine($user, $afn, $cashLedger, 'CM-4', '2026-11-01', 'posted', '50', '0');
        $this->createJournalLine($user, $afn, $cashLedger, 'CM-5', '2026-10-20', 'draft', '0', '25');
        $this->createJournalLine($user, $usd, $bankLedger, 'CM-6', '2026-10-12', 'posted', '5', '0');

        $response = $this->actingAs($user)->get(route('finance.cash-movements.index', ['from' => '2026-10-01', 'to' => '2026-10-31']));
        $response->assertOk()->assertSee('Factory cash')->assertSee('USD bank')->assertSee('100')->assertSee('30')->assertSee('10')->assertSee('120')->assertSee('5')->assertDontSee('>170</span>', false)->assertDontSee('>35</span>', false);
        $this->actingAs($user)->get(route('finance.cash-movements.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'currency_id' => $afn->id]))
            ->assertOk()->assertSee('Factory cash')->assertDontSee('USD bank');
    }

    public function test_cash_movement_csv_and_access_control(): void
    {
        $user = $this->allowedUser();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        [$account, $ledger] = $this->makeFinancialAccount($user, $currency, 'sarafi', 'Sarafi counter');
        $this->createJournalLine($user, $currency, $ledger, 'CM-CSV', '2026-10-10', 'posted', '0.3003', '0');
        $response = $this->actingAs($user)->get(route('finance.cash-movements.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'csv']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($account->name, $response->streamedContent());
        $this->assertStringContainsString('0.3003', $response->streamedContent());
        $this->actingAs(User::factory()->create())->get(route('finance.cash-movements.index'))->assertForbidden();
    }
}
