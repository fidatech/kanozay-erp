<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\MoneyTransaction;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QuickPartyTransactionTest extends TestCase
{
    use RefreshDatabase;
    private function setupData():array {
        $this->seed(ChartOfAccountsSeeder::class);
        $user=User::factory()->create();$user->givePermissionTo(Permission::findOrCreate('finance.update','web'));
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $cash=FinancialAccount::create(['type'=>'cashbox','name'=>'Main cashbox','currency_id'=>$currency->id,'is_active'=>true,'created_by'=>$user->id]);
        $provisioner=app(EntityAccountProvisioner::class);$provisioner->provision('cashbox',$cash->id,$cash->name,$currency->id);
        $customer=TradeParty::create(['type'=>'customer','name'=>'Sample Customer','is_active'=>true,'created_by'=>$user->id]);
        $customer->currencies()->attach($currency->id);$provisioner->provision('customer',$customer->id,$customer->name,$currency->id);
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Sample Supplier','is_active'=>true,'created_by'=>$user->id]);
        $supplier->currencies()->attach($currency->id);$provisioner->provision('supplier',$supplier->id,$supplier->name,$currency->id);
        return [$user,$currency,$cash,$customer,$supplier];
    }
    private function payload(TradeParty $party,Currency $currency,array $overrides=[]):array {
        return array_merge(['submission_token'=>(string)Str::uuid(),'transaction_date'=>'2026-11-04','trade_party_id'=>$party->id,'type'=>'credit','currency_id'=>$currency->id,'amount'=>'1,250.5000','description'=>'Customer settlement'], $overrides);
    }
    public function test_customer_credit_posts_cash_debit_party_credit_and_appears_in_register():void {
        [$user,$currency,$cash,$customer]=$this->setupData();
        $data=$this->payload($customer,$currency);
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$data)->assertRedirect(route('finance.journals.index'));
        $txn=MoneyTransaction::firstOrFail();$journal=$txn->journal;
        $this->assertSame('receipt',$txn->type);$this->assertSame($cash->id,$txn->destination_account_id);$this->assertSame('posted',$journal->status);
        $this->assertCount(2,$journal->lines);$this->assertSame('1250.5000',(string)\Brick\Math\BigDecimal::of((string)$journal->lines->sum('debit'))->toScale(4));
        $mapping=\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where(['entity_type'=>'customer','entity_id'=>$customer->id,'currency_id'=>$currency->id,'purpose'=>'receivable'])->value('ledger_account_id');
        $this->assertSame((int)$mapping,$journal->lines->firstWhere('credit','1250.5000')?->ledger_account_id);
        $this->actingAs($user)->get(route('finance.journals.index'))->assertOk()->assertSee('Quick Credit / Debit')->assertSee($customer->name)->assertSee($journal->number);
        $this->assertDatabaseHas('activity_logs',['action'=>'party_transaction.posted']);
    }
    public function test_supplier_debit_posts_party_debit_cash_credit():void {
        [$user,$currency,$cash,$customer,$supplier]=$this->setupData();
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$this->payload($supplier,$currency,['type'=>'debit','amount'=>'500','description'=>'Supplier payment']))->assertRedirect();
        $txn=MoneyTransaction::firstOrFail();$this->assertSame('payment',$txn->type);$this->assertSame($cash->id,$txn->source_account_id);
        $this->assertSame('posted',$txn->journal->status);
        $this->assertDatabaseHas('journal_lines',['journal_entry_id'=>$txn->journal->id,'debit'=>'500']);
        $this->actingAs($user)->get(route('finance.journals.index',['tx_type'=>'payment']))->assertOk()->assertSee('Supplier payment');
        $this->actingAs($user)->get(route('finance.journals.index',['tx_type'=>'receipt']))->assertOk()->assertDontSee('Supplier payment');
    }
    public function test_duplicate_token_does_not_duplicate_money_or_journal():void {
        [$user,$currency,$cash,$customer]=$this->setupData();$payload=$this->payload($customer,$currency);
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$payload)->assertRedirect();
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$payload)->assertRedirect();
        $this->assertDatabaseCount('money_transactions',1);$this->assertDatabaseCount('journal_entries',1);
    }
    public function test_rejects_wrong_party_currency_and_ambiguous_cashboxes_without_posting():void {
        [$user,$currency,$cash,$customer]=$this->setupData();
        $other=Currency::create(['code'=>'USD','name'=>'Dollar','is_active'=>true]);
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$this->payload($customer,$other))->assertSessionHasErrors('currency_id');
        $second=FinancialAccount::create(['type'=>'cashbox','name'=>'Second','currency_id'=>$currency->id,'is_active'=>true,'created_by'=>$user->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox',$second->id,$second->name,$currency->id);
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$this->payload($customer,$currency))->assertSessionHasErrors('cash_account_id');
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$this->payload($customer,$currency,['cash_account_id'=>$second->id]))->assertRedirect();
        $this->assertDatabaseCount('money_transactions',1);
    }
    public function test_invalid_amount_or_unprivileged_user_cannot_post():void {
        [$user,$currency,$cash,$customer]=$this->setupData();
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$this->payload($customer,$currency,['amount'=>'0.00001']))->assertSessionHasErrors('amount');
        $this->actingAs(User::factory()->create())->post(route('finance.journals.quick.store'),$this->payload($customer,$currency))->assertForbidden();
        $this->assertDatabaseCount('journal_entries',0);
    }
    public function test_cashbox_empty_state_and_inline_create_provisions_account_without_balance():void {
        $this->seed(ChartOfAccountsSeeder::class);
        $admin=User::factory()->create();$admin->givePermissionTo(Permission::findOrCreate('finance.update','web'));
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true,'is_active'=>true]);
        $this->actingAs($admin)->get(route('finance.journals.index'))->assertOk()
            ->assertSee('No active cashbox exists.')
            ->assertSee('qt-new-cashbox')->assertSee('value="__add_new__"',false)->assertDontSee('id="qt-add-cash"',false)->assertSee('select2:select.qtCashbox')
            ->assertSee('qt-cashbox-currency')->assertSee('Currency (automatic)')
            ->assertSeeInOrder(['id="qt-cash-wrap"','Currency (automatic)'],false)
            ->assertSee('readonly aria-label="Currency selected by cashbook"',false)
            ->assertSee('syncCurrency')->assertSee('renderCashbooks');
        $this->actingAs($admin)->postJson(route('finance.accounts.store'),[
            'type'=>'cashbox','name'=>'Testing cashbox','currency_id'=>$currency->id,
        ])->assertCreated()->assertJsonPath('account.name','Testing cashbox')
          ->assertJsonPath('account.currency_id',$currency->id)
          ->assertJsonPath('account.type','cashbox');
        $cash=FinancialAccount::sole();
        $this->assertDatabaseHas('entity_ledger_accounts',[
            'entity_type'=>'cashbox','entity_id'=>$cash->id,'currency_id'=>$currency->id,'purpose'=>'cash',
        ]);
        $this->assertDatabaseCount('journal_entries',0);
        $this->assertDatabaseCount('money_transactions',0);
    }
    public function test_cashbox_modal_creation_requires_finance_write_permission():void {
        $this->seed(ChartOfAccountsSeeder::class);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $this->actingAs(User::factory()->create())->postJson(route('finance.accounts.store'),[
            'type'=>'cashbox','name'=>'Unauthorized','currency_id'=>$currency->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('financial_accounts',0);
    }

    public function test_explicit_cashbook_cannot_be_posted_using_another_currency():void {
        [$user,$afn,$cash,$customer]=$this->setupData();
        $usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true]);
        $customer->currencies()->attach($usd->id);
        app(EntityAccountProvisioner::class)->provision('customer',$customer->id,$customer->name,$usd->id);
        $usdCash=FinancialAccount::create(['type'=>'cashbox','name'=>'USD Cashbook','currency_id'=>$usd->id,'is_active'=>true,'created_by'=>$user->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox',$usdCash->id,$usdCash->name,$usd->id);
        $payload=$this->payload($customer,$usd,['cash_account_id'=>$cash->id]);
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$payload)->assertSessionHasErrors('cash_account_id');
        $this->assertDatabaseCount('money_transactions',0);
        $this->assertDatabaseCount('journal_entries',0);
        $payload['cash_account_id']=$usdCash->id;
        $this->actingAs($user)->post(route('finance.journals.quick.store'),$payload)->assertRedirect();
        $this->assertDatabaseHas('money_transactions',['currency_id'=>$usd->id,'destination_account_id'=>$usdCash->id]);
    }

}
