<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UnifiedQuickAccountTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('finance.update', 'web'));
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true]);
        $provisioner = app(EntityAccountProvisioner::class);
        $cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'AFN Cash', 'currency_id' => $afn->id, 'is_active' => true, 'created_by' => $user->id]);
        $bank = FinancialAccount::create(['type' => 'bank', 'name' => 'AFN Bank', 'currency_id' => $afn->id, 'is_active' => true, 'created_by' => $user->id]);
        $usdCash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'USD Cash', 'currency_id' => $usd->id, 'is_active' => true, 'created_by' => $user->id]);
        foreach ([$cash, $bank, $usdCash] as $account) {
            $provisioner->provision($account->type, $account->id, $account->name, $account->currency_id);
        }
        $customer = TradeParty::create(['type' => 'customer', 'name' => 'Test Customer', 'is_active' => true, 'created_by' => $user->id]);
        $customer->currencies()->attach($afn->id);
        $provisioner->provision('customer', $customer->id, $customer->name, $afn->id);
        $expense = LedgerAccount::where('code', '5110')->first()
            ?? LedgerAccount::create(['code' => 'EXP-TEST', 'name' => 'Test Miscellaneous Expense', 'type' => 'expense', 'is_group' => false, 'is_active' => true]);
        return [$user, $afn, $usd, $cash, $bank, $usdCash, $customer, $expense];
    }

    private function payload(FinancialAccount $cash, string $counterpart, array $overrides = []): array
    {
        return array_merge([
            'submission_token' => (string) Str::uuid(), 'transaction_date' => '2026-11-04',
            'cash_account_id' => $cash->id, 'currency_id' => $cash->currency_id,
            'counterpart_key' => $counterpart, 'type' => 'credit',
            'amount' => '1,200.5000', 'description' => 'Quick account test',
        ], $overrides);
    }

    public function test_customer_credit_uses_existing_party_accounting(): void
    {
        [$user, $afn, $usd, $cash, $bank, $usdCash, $customer] = $this->fixture();
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'),
            $this->payload($cash, 'party:' . $customer->id))->assertRedirect(route('finance.journals.index'));
        $money = MoneyTransaction::sole();
        $this->assertSame('receipt', $money->type);
        $this->assertSame($cash->id, $money->destination_account_id);
        $this->assertSame('posted', $money->journal->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'party_transaction.posted']);
    }

    public function test_same_currency_financial_transfer_posts_two_lines_and_is_not_duplicated(): void
    {
        [$user, $afn, $usd, $cash, $bank] = $this->fixture();
        $payload = $this->payload($cash, 'financial:' . $bank->id, ['type' => 'debit', 'amount' => '4,500']);
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('money_transactions', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $money = MoneyTransaction::sole();
        $this->assertSame('transfer', $money->type);
        $this->assertSame($cash->id, $money->source_account_id);
        $this->assertSame($bank->id, $money->destination_account_id);
        $this->assertSame('posted', $money->journal->status);
        $cashLedger = DB::table('entity_ledger_accounts')->where(['entity_type'=>'cashbox','entity_id'=>$cash->id,'currency_id'=>$afn->id,'purpose'=>'cash'])->value('ledger_account_id');
        $bankLedger = DB::table('entity_ledger_accounts')->where(['entity_type'=>'bank','entity_id'=>$bank->id,'currency_id'=>$afn->id,'purpose'=>'cash'])->value('ledger_account_id');
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id'=>$money->journal_entry_id,'ledger_account_id'=>$cashLedger,'credit'=>'4500']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id'=>$money->journal_entry_id,'ledger_account_id'=>$bankLedger,'debit'=>'4500']);
    }

    public function test_cross_currency_transfer_and_self_transfer_are_rejected(): void
    {
        [$user, $afn, $usd, $cash, $bank, $usdCash] = $this->fixture();
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'),
            $this->payload($cash, 'financial:' . $usdCash->id))->assertSessionHasErrors('counterpart_key');
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'),
            $this->payload($cash, 'financial:' . $cash->id))->assertSessionHasErrors('counterpart_key');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_gl_counterpart_uses_unmapped_ledger_and_rejects_mapped_ledger(): void
    {
        [$user, $afn, $usd, $cash, $bank, $usdCash, $customer, $expense] = $this->fixture();
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'),
            $this->payload($cash, 'ledger:' . $expense->id, ['type' => 'debit','amount'=>'210']))->assertRedirect();
        $this->assertDatabaseHas('money_transactions', ['type'=>'payment','counterpart_ledger_account_id'=>$expense->id]);
        $mapped = DB::table('entity_ledger_accounts')->where(['entity_type'=>'customer','entity_id'=>$customer->id,'purpose'=>'receivable'])->value('ledger_account_id');
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'),
            $this->payload($cash, 'ledger:' . $mapped))->assertSessionHasErrors('counterpart_key');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_currency_tampering_and_unauthorized_posting_are_rejected(): void
    {
        [$user, $afn, $usd, $cash, $bank, $usdCash] = $this->fixture();
        $payload = $this->payload($cash, 'financial:' . $bank->id, ['currency_id'=>$usd->id]);
        $this->actingAs($user)->post(route('finance.journals.quick-account.store'), $payload)
            ->assertSessionHasErrors('cash_account_id');
        $this->actingAs(User::factory()->create())
            ->post(route('finance.journals.quick-account.store'), $this->payload($cash,'financial:' . $bank->id))
            ->assertForbidden();
        $this->assertDatabaseCount('money_transactions', 0);
    }

    public function test_journal_page_shows_all_account_groups_and_selects_party_from_directory(): void
    {
        [$user, $afn, $usd, $cash, $bank, $usdCash, $customer] = $this->fixture();
        $this->actingAs($user)->get(route('finance.journals.index', ['trade_party_id'=>$customer->id]))
            ->assertOk()->assertSee('Account / Counterpart')
            ->assertSee('Customers')->assertSee('Suppliers')
            ->assertSee('Cashbooks, Banks and Sarafis')
            ->assertSee('Other General Ledger Accounts')
            ->assertSee('value="party:'.$customer->id.'"', false);
    }
}
