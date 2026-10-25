<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class MoneyTransactionTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array {$user=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$user->givePermissionTo(['administration.view','administration.update']);$this->seed(ChartOfAccountsSeeder::class);$currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);$first=FinancialAccount::create(['type'=>'cashbox','name'=>'Main cash','currency_id'=>$currency->id,'created_by'=>$user->id]);$second=FinancialAccount::create(['type'=>'bank','name'=>'Main bank','currency_id'=>$currency->id,'created_by'=>$user->id]);$service=app(EntityAccountProvisioner::class);$service->provision('cashbox',$first->id,$first->name,$currency->id);$service->provision('bank',$second->id,$second->name,$currency->id);return [$user,$first,$second];}
 public function test_transfer_posts_balanced_journal():void {[$user,$first,$second]=$this->fixture();$this->actingAs($user)->post(route('finance.money.store'),['type'=>'transfer','transaction_date'=>'2026-10-25','source_account_id'=>$first->id,'destination_account_id'=>$second->id,'amount'=>'100.2500','description'=>'Bank deposit'])->assertRedirect(route('finance.money.index'));$transaction=MoneyTransaction::firstOrFail();$this->assertSame('posted',$transaction->journal->status);$this->assertCount(2,$transaction->journal->lines);$this->assertEquals(100.25,$transaction->journal->lines->sum('debit'));$this->assertEquals(100.25,$transaction->journal->lines->sum('credit'));}
 public function test_receipt_uses_counterpart_and_posts():void {[$user,$first]=$this->fixture();$counterpart=LedgerAccount::where('code','4110')->first();$this->assertNotNull($counterpart);$this->actingAs($user)->post(route('finance.money.store'),['type'=>'receipt','transaction_date'=>'2026-10-25','destination_account_id'=>$first->id,'counterpart_ledger_account_id'=>$counterpart->id,'amount'=>25,'description'=>'Other receipt'])->assertRedirect(route('finance.money.index'));$this->assertDatabaseCount('money_transactions',1);}
 public function test_transfer_cannot_use_same_account():void {[$user,$first]=$this->fixture();$this->actingAs($user)->post(route('finance.money.store'),['type'=>'transfer','transaction_date'=>'2026-10-25','source_account_id'=>$first->id,'destination_account_id'=>$first->id,'amount'=>10,'description'=>'Invalid'])->assertSessionHasErrors('destination_account_id');$this->assertDatabaseCount('money_transactions',0);}
}
