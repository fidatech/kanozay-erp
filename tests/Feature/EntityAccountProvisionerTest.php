<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Services\Accounting\EntityAccountProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class EntityAccountProvisionerTest extends TestCase {
 use RefreshDatabase;
 public function test_seed_is_idempotent_and_contains_manufacturing_accounts():void {$this->seed(ChartOfAccountsSeeder::class);$count=LedgerAccount::count();$this->seed(ChartOfAccountsSeeder::class);$this->assertSame($count,LedgerAccount::count());$this->assertGreaterThan(80,$count);$this->assertDatabaseHas('ledger_accounts',['code'=>'1143','name'=>'Work in Progress']);}
 public function test_each_currency_gets_distinct_linked_customer_and_supplier_accounts():void {$this->seed(ChartOfAccountsSeeder::class);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true]);$service=app(EntityAccountProvisioner::class);$a=$service->provision('customer',7,'Test Customer',$afn->id);$again=$service->provision('customer',7,'Test Customer',$afn->id);$b=$service->provision('customer',7,'Test Customer',$usd->id);$supplier=$service->provision('supplier',8,'Test Supplier',$usd->id);$this->assertSame($a['receivable']->id,$again['receivable']->id);$this->assertNotSame($a['receivable']->id,$b['receivable']->id);$this->assertSame('liability',$supplier['payable']->type);$this->assertDatabaseCount('entity_ledger_accounts',6);}
 public function test_sarafi_and_shareholder_get_their_expected_account_types():void {$this->seed(ChartOfAccountsSeeder::class);$currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true]);$service=app(EntityAccountProvisioner::class);$this->assertSame('asset',$service->provision('sarafi',1,'Exchange Counter',$currency->id)['cash']->type);$this->assertCount(3,$service->provision('shareholder',1,'Shareholder',$currency->id));}
}
