<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class FinancialAccountTest extends TestCase {
 use RefreshDatabase;
 public function test_creating_bank_account_provisions_currency_specific_ledger():void {
  $user=User::factory()->create();Permission::findOrCreate('administration.update','web');Permission::findOrCreate('administration.view','web');$user->givePermissionTo(['administration.update','administration.view']);$this->seed(ChartOfAccountsSeeder::class);$currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $this->actingAs($user)->post(route('finance.accounts.store'),['type'=>'bank','name'=>'Operating Bank','currency_id'=>$currency->id])->assertRedirect(route('finance.accounts.index'));
  $account=FinancialAccount::firstOrFail();$this->assertSame('bank',$account->type);$this->assertDatabaseHas('entity_ledger_accounts',['entity_type'=>'bank','entity_id'=>$account->id,'currency_id'=>$currency->id,'purpose'=>'cash']);$this->assertSame(1,DB::table('entity_ledger_accounts')->count());
 }
 public function test_user_without_permission_cannot_create_account():void {$this->actingAs(User::factory()->create())->post(route('finance.accounts.store'),['type'=>'cashbox','name'=>'Test','currency_id'=>1])->assertForbidden();}

 private function cashboxFixture():array {
  $this->seed(ChartOfAccountsSeeder::class);
  $user=User::factory()->create();
  $user->givePermissionTo(Permission::findOrCreate('finance.update','web'));
  $afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true]);
  $this->actingAs($user)->post(route('finance.accounts.store'),[
   'type'=>'cashbox','name'=>'Main Cash USD','currency_id'=>$afn->id
  ])->assertRedirect();
  return [$user,$afn,$usd,FinancialAccount::sole()];
 }
 public function test_unused_cashbox_can_change_currency_without_creating_balances():void {
  [$user,$afn,$usd,$account]=$this->cashboxFixture();
  $previous=DB::table('entity_ledger_accounts')->where('entity_type','cashbox')->where('entity_id',$account->id)->first();
  $this->actingAs($user)->put(route('finance.accounts.update',$account),[
   'name'=>'Main Cash USD','currency_id'=>$usd->id,'is_active'=>1
  ])->assertRedirect(route('finance.accounts.index'));
  $this->assertDatabaseHas('financial_accounts',['id'=>$account->id,'currency_id'=>$usd->id]);
  $link=DB::table('entity_ledger_accounts')->where('entity_type','cashbox')->where('entity_id',$account->id)->sole();
  $this->assertSame($usd->id,(int)$link->currency_id);
  $this->assertNotEquals($previous->ledger_account_id,$link->ledger_account_id);
  $this->assertDatabaseMissing('ledger_accounts',['id'=>$previous->ledger_account_id]);
  $this->assertDatabaseCount('journal_entries',0);
  $this->assertDatabaseCount('money_transactions',0);
  $this->assertDatabaseHas('activity_logs',['action'=>'financial_account.updated','subject_id'=>$account->id]);
 }
 public function test_unused_financial_account_can_be_deleted_with_its_ledger():void {
  [$user,$afn,$usd,$account]=$this->cashboxFixture();
  $ledgerId=DB::table('entity_ledger_accounts')->where('entity_type','cashbox')->where('entity_id',$account->id)->value('ledger_account_id');
  $this->actingAs($user)->delete(route('finance.accounts.destroy',$account))->assertRedirect(route('finance.accounts.index'));
  $this->assertDatabaseMissing('financial_accounts',['id'=>$account->id]);
  $this->assertDatabaseMissing('ledger_accounts',['id'=>$ledgerId]);
  $this->assertDatabaseMissing('entity_ledger_accounts',['entity_type'=>'cashbox','entity_id'=>$account->id]);
  $this->assertDatabaseHas('activity_logs',['action'=>'financial_account.deleted','subject_id'=>$account->id]);
 }
 public function test_used_cashbox_cannot_change_currency_or_be_deleted():void {
  [$user,$afn,$usd,$account]=$this->cashboxFixture();
  $cashLedger=DB::table('entity_ledger_accounts')->where('entity_type','cashbox')->where('entity_id',$account->id)->value('ledger_account_id');
  $journal=\App\Models\JournalEntry::create(['number'=>'J-CASH-01','entry_date'=>'2026-11-04','description'=>'Test transaction','currency_id'=>$afn->id,'status'=>'posted','created_by'=>$user->id]);
  $journal->lines()->create(['ledger_account_id'=>$cashLedger,'debit'=>'100','credit'=>'0']);
  $this->actingAs($user)->put(route('finance.accounts.update',$account),['name'=>'Main Cash USD','currency_id'=>$usd->id,'is_active'=>1])->assertSessionHasErrors('currency_id');
  $this->actingAs($user)->delete(route('finance.accounts.destroy',$account))->assertSessionHasErrors('currency_id');
  $this->assertDatabaseHas('financial_accounts',['id'=>$account->id,'currency_id'=>$afn->id]);
  $this->assertDatabaseHas('ledger_accounts',['id'=>$cashLedger]);
 }
 public function test_financial_account_edit_view_shows_currency_and_delete_confirmation():void {
  [$user,$afn,$usd,$account]=$this->cashboxFixture();
  $this->actingAs($user)->get(route('finance.accounts.index'))->assertOk()
   ->assertSee('name="currency_id"',false)->assertSee('fa-delete-form')->assertSee('Yes, delete')
   ->assertSee('Accounts with financial history cannot be deleted.');
 }
 public function test_unauthorized_user_cannot_delete_or_change_financial_account():void {
  [$user,$afn,$usd,$account]=$this->cashboxFixture();
  $other=User::factory()->create();
  $this->actingAs($other)->delete(route('finance.accounts.destroy',$account))->assertForbidden();
  $this->actingAs($other)->put(route('finance.accounts.update',$account),[
   'name'=>'Modified','currency_id'=>$usd->id,'is_active'=>1
  ])->assertForbidden();
 }
}
