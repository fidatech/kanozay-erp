<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\TradeParty;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class TradePartyTest extends TestCase {
 use RefreshDatabase;
 private function makeFixtures():array{$user=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$user->givePermissionTo(['administration.view','administration.update']);$this->seed(ChartOfAccountsSeeder::class);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_active'=>true]);return [$user,$afn,$usd];}
 public function test_supplier_creates_payable_and_advance_accounts():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'supplier','name'=>'Test Supplier','currency_ids'=>[$afn->id]])->assertRedirect();$party=TradeParty::firstOrFail();$this->assertSame('supplier',$party->type);$this->assertDatabaseCount('entity_ledger_accounts',2);$this->assertDatabaseHas('entity_ledger_accounts',['entity_type'=>'supplier','entity_id'=>$party->id,'purpose'=>'payable']);}
 public function test_customer_can_enable_second_currency():void {[$user,$afn,$usd]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Test Customer','currency_ids'=>[$afn->id]])->assertRedirect();$party=TradeParty::firstOrFail();$this->actingAs($user)->post(route('finance.parties.currencies.store',$party),['currency_id'=>$usd->id])->assertRedirect();$this->assertDatabaseCount('entity_ledger_accounts',4);$this->assertCount(2,$party->fresh()->currencies);}
 public function test_customer_register_renders_without_blade_parse_error():void {[$user]=$this->makeFixtures();$this->actingAs($user)->get(route('finance.parties.index',['type'=>'customer']))->assertOk()->assertSee('Customer Register');}
 public function test_supplier_register_renders_without_blade_parse_error():void {[$user]=$this->makeFixtures();$this->actingAs($user)->get(route('finance.parties.index',['type'=>'supplier']))->assertOk()->assertSee('Supplier Register');}
 public function test_invalid_currency_does_not_create_party():void {[$user]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Test','currency_ids'=>[999]])->assertSessionHasErrors('currency_ids.0');$this->assertDatabaseCount('trade_parties',0);}
}
