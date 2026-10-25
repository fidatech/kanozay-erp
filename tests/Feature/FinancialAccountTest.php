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
}
