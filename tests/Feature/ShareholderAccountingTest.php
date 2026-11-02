<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Shareholder;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShareholderAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_contribution_and_withdrawal_post_balanced_journals_once(): void
    {
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class]);
        [$user, $currency, $account] = $this->setupActorAndAccount();

        $this->actingAs($user)->post(route('shareholders.store'), [
            'name' => 'Synthetic Shareholder',
            'code' => 'SYN-SH-01',
            'currency_ids' => [$currency->id],
        ])->assertRedirect();
        $shareholder = Shareholder::where('code', 'SYN-SH-01')->firstOrFail();

        $key = (string) Str::uuid();
        $contribution = [
            'idempotency_key' => $key,
            'type' => 'contribution',
            'transaction_date' => '2026-11-02',
            'currency_id' => $currency->id,
            'financial_account_id' => $account->id,
            'amount' => '1250.5000',
            'reference' => 'SYNTHETIC-CAP-01',
            'description' => 'Synthetic capital contribution for testing.',
        ];
        $this->actingAs($user)->post(route('shareholders.transactions.store', $shareholder), $contribution)->assertRedirect();
        $this->actingAs($user)->post(route('shareholders.transactions.store', $shareholder), $contribution)->assertRedirect();
        $this->assertDatabaseCount('shareholder_transactions', 1);

        $transaction = $shareholder->transactions()->firstOrFail();
        $journal = JournalEntry::with('lines')->findOrFail($transaction->journal_entry_id);
        $this->assertSame('posted', $journal->status);
        $this->assertSame('JE-SHC-000001', $journal->number);
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();
        foreach ($journal->lines as $line) {
            $debits = $debits->plus((string) $line->debit);
            $credits = $credits->plus((string) $line->credit);
        }
        $this->assertTrue($debits->isEqualTo(BigDecimal::of('1250.5000')));
        $this->assertTrue($credits->isEqualTo(BigDecimal::of('1250.5000')));

        $withdrawal = array_merge($contribution, ['idempotency_key' => (string) Str::uuid(), 'type' => 'withdrawal', 'amount' => '250.0000', 'reference' => 'SYNTHETIC-WD-01']);
        $this->actingAs($user)->post(route('shareholders.transactions.store', $shareholder), $withdrawal)->assertRedirect();
        $this->assertDatabaseCount('shareholder_transactions', 2);
        $this->assertDatabaseHas('shareholder_transactions', ['type' => 'withdrawal', 'amount' => '250.0000']);
        $this->assertSame(2, JournalEntry::where('status', 'posted')->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_amount(): void
    {
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class]);
        [$user, $currency, $account] = $this->setupActorAndAccount();
        $shareholder = Shareholder::create(['name' => 'Key Test Shareholder', 'is_active' => true, 'created_by' => $user->id]);
        $shareholder->currencies()->attach($currency->id);
        $shareholder->provisionLedgerAccountsForCurrency($currency->id);
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'type' => 'contribution', 'transaction_date' => '2026-11-02', 'currency_id' => $currency->id, 'financial_account_id' => $account->id, 'amount' => '10.0000', 'description' => 'Synthetic contribution.'];
        $this->actingAs($user)->post(route('shareholders.transactions.store', $shareholder), $payload)->assertRedirect();
        $payload['amount'] = '11.0000';
        $this->actingAs($user)->post(route('shareholders.transactions.store', $shareholder), $payload)->assertSessionHasErrors('idempotency_key');
        $this->assertDatabaseCount('shareholder_transactions', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_shareholder_pages_require_scoped_permission(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('shareholders.view', 'web');
        $this->actingAs($user)->get(route('shareholders.index'))->assertForbidden();
        $user->givePermissionTo('shareholders.view');
        $this->actingAs($user)->get(route('shareholders.index'))->assertOk();
    }

    private function setupActorAndAccount(): array
    {
        $user = User::factory()->create();
        foreach (['shareholders.view', 'shareholders.create', 'shareholders.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['shareholders.view', 'shareholders.create', 'shareholders.update']);

        $currency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'is_base' => true]);
        $account = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic Cashbox', 'currency_id' => $currency->id, 'is_active' => true, 'created_by' => $user->id]);
        app(\App\Services\Accounting\EntityAccountProvisioner::class)->provision('cashbox', $account->id, $account->name, $currency->id);

        return [$user, $currency, $account];
    }
}
