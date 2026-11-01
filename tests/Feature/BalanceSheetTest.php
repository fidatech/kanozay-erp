<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialSetting;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private function allowedUser(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        return $user;
    }

    private function account(string $code, string $name, string $type): LedgerAccount
    {
        return LedgerAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true, 'is_group' => false]);
    }

    private function journal(User $user, Currency $currency, string $number, string $date, string $status, array $lines): void
    {
        $entry = JournalEntry::create(['number' => $number, 'entry_date' => $date, 'description' => $number, 'status' => $status, 'currency_id' => $currency->id, 'created_by' => $user->id]);
        $entry->lines()->createMany($lines);
    }

    public function test_balance_sheet_uses_fiscal_year_earnings_and_balances_per_currency(): void
    {
        $user = $this->allowedUser();
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true, 'is_base' => false]);
        FinancialSetting::create(['fiscal_year_start' => '04-01', 'allow_negative_stock' => false, 'require_payment_approval' => true, 'require_purchase_approval' => true, 'purchase_mode' => 'both']);
        $cash = $this->account('1111', 'Cash', 'asset');
        $payable = $this->account('2100', 'Loan payable', 'liability');
        $capital = $this->account('3100', 'Owner capital', 'equity');
        $income = $this->account('4100', 'Sales', 'income');
        $expense = $this->account('5100', 'Utilities', 'expense');

        $this->journal($user, $afn, 'BS-1', '2026-03-15', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
            ['ledger_account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
        ]);
        $this->journal($user, $afn, 'BS-2', '2026-04-10', 'posted', [
            ['ledger_account_id' => $expense->id, 'debit' => '100', 'credit' => '0'],
            ['ledger_account_id' => $cash->id, 'debit' => '0', 'credit' => '100'],
        ]);
        $this->journal($user, $afn, 'BS-3', '2026-04-15', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '400', 'credit' => '0'],
            ['ledger_account_id' => $income->id, 'debit' => '0', 'credit' => '400'],
        ]);
        $this->journal($user, $afn, 'BS-4', '2026-04-20', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '200', 'credit' => '0'],
            ['ledger_account_id' => $payable->id, 'debit' => '0', 'credit' => '200'],
        ]);
        $this->journal($user, $afn, 'BS-5', '2026-05-01', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '100', 'credit' => '0'],
            ['ledger_account_id' => $income->id, 'debit' => '0', 'credit' => '100'],
        ]);
        $this->journal($user, $afn, 'BS-6', '2026-04-22', 'draft', [
            ['ledger_account_id' => $expense->id, 'debit' => '50', 'credit' => '0'],
            ['ledger_account_id' => $cash->id, 'debit' => '0', 'credit' => '50'],
        ]);
        $this->journal($user, $usd, 'BS-7', '2026-04-15', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '10', 'credit' => '0'],
            ['ledger_account_id' => $capital->id, 'debit' => '0', 'credit' => '10'],
        ]);

        $response = $this->actingAs($user)->get(route('finance.balance-sheet.index', ['as_of' => '2026-04-30']));
        $response->assertOk()->assertSee('2026-04-01')->assertSee('AFN Balance Sheet')->assertSee('USD Balance Sheet')
            ->assertSee('1,500')->assertSee('1,000')->assertSee('300')->assertDontSee('1,600')->assertDontSee('350');
        $this->actingAs($user)->get(route('finance.balance-sheet.index', ['as_of' => '2026-04-30', 'currency_id' => $afn->id]))
            ->assertOk()->assertSee('AFN Balance Sheet')->assertDontSee('USD Balance Sheet');
    }

    public function test_csv_balance_sheet_export_contains_reconciliation_total(): void
    {
        $user = $this->allowedUser();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $cash = $this->account('1111', 'Cash', 'asset');
        $capital = $this->account('3100', 'Owner capital', 'equity');
        $this->journal($user, $currency, 'BS-CSV', '2026-04-15', 'posted', [
            ['ledger_account_id' => $cash->id, 'debit' => '0.3003', 'credit' => '0'],
            ['ledger_account_id' => $capital->id, 'debit' => '0', 'credit' => '0.3003'],
        ]);
        $response = $this->actingAs($user)->get(route('finance.balance-sheet.index', ['as_of' => '2026-04-30', 'export' => 'csv']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('0.3003', $response->streamedContent());
    }

    public function test_balance_sheet_requires_finance_view_permission(): void
    {
        $this->actingAs(User::factory()->create())->get(route('finance.balance-sheet.index'))->assertForbidden();
    }
}
