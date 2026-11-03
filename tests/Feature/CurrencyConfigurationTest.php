<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CurrencyConfigurationTest extends TestCase {
 use RefreshDatabase;
 private function admin(): User { $u=User::factory()->create(); $u->givePermissionTo([Permission::findOrCreate('administration.view','web'),Permission::findOrCreate('administration.update','web')]);return $u; }
 public function test_registration_normalizes_code_and_rejects_duplicates():void { $a=$this->admin();$p=['code'=>'usd','name'=>'US Dollar','decimal_places'=>2];$this->actingAs($a)->post(route('admin.currencies.store'),$p)->assertRedirect();$this->assertDatabaseHas('currencies',['code'=>'USD','is_base'=>true]);$this->actingAs($a)->post(route('admin.currencies.store'),$p)->assertSessionHasErrors('code');$this->assertDatabaseCount('currencies',1); }
 public function test_base_change_is_locked_by_financial_records_even_without_rates():void { $a=$this->admin();$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'Dollar']);FinancialAccount::create(['name'=>'Cash','type'=>'cashbox','currency_id'=>$afn->id,'created_by'=>$a->id]);$this->actingAs($a)->patch(route('admin.currencies.base',$usd))->assertSessionHasErrors('currency');$this->assertTrue($afn->fresh()->is_base); }
 public function test_dated_rate_creates_audited_historical_snapshot_and_rejects_invalid_pair():void { $a=$this->admin();$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'Dollar']);$p=['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'rate'=>'65.125','effective_date'=>'2026-11-02','source'=>'Test rate'];$this->actingAs($a)->post(route('admin.exchange-rates.store'),$p)->assertRedirect();$this->actingAs($a)->post(route('admin.exchange-rates.store'),array_merge($p,['rate'=>'65.250']))->assertRedirect();$this->assertDatabaseCount('exchange_rates',2);$this->assertDatabaseHas('exchange_rates',['rate'=>'65.125000000000']);$this->assertDatabaseHas('activity_logs',['action'=>'exchange_rate.recorded']);$this->actingAs($a)->post(route('admin.exchange-rates.store'),array_merge($p,['to_currency_id'=>$usd->id]))->assertSessionHasErrors('to_currency_id'); }
 public function test_unprivileged_user_is_denied_currency_and_rate_changes():void { $u=User::factory()->create();$this->actingAs($u)->get(route('admin.currencies.index'))->assertForbidden();$this->actingAs($u)->post(route('admin.currencies.store'),[])->assertForbidden();$this->actingAs($u)->post(route('admin.exchange-rates.store'),[])->assertForbidden(); }
 public function test_historical_conversion_uses_latest_eligible_rate_and_decimal_rounding():void {
  $a=$this->admin();$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_base'=>true]);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','decimal_places'=>2]);
  foreach ([['2026-10-01','65.100000000000'],['2026-11-01','65.250000000000'],['2026-12-01','80.000000000000']] as [$date,$value]) ExchangeRate::create(['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'rate'=>$value,'effective_date'=>$date,'created_by'=>$a->id]);
  $url=route('admin.exchange-rates.convert');$p=['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'amount'=>'2.000000','as_of'=>'2026-11-02'];
  $this->actingAs($a)->getJson($url.'?'.http_build_query($p))->assertOk()->assertJsonPath('converted_amount','130.50')->assertJsonPath('effective_date','2026-11-01');
  $this->actingAs($a)->getJson($url.'?'.http_build_query(array_merge($p,['as_of'=>'2026-09-01'])))->assertNotFound();
  $this->actingAs($a)->getJson($url.'?'.http_build_query(array_merge($p,['from_currency_id'=>$afn->id,'to_currency_id'=>$usd->id])))->assertNotFound();
  $this->assertDatabaseCount('exchange_rates',3);
 }

 public function test_rate_board_displays_both_directions_and_currency_modal():void {
  $admin=$this->admin();$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_base'=>true]);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani']);$pkr=Currency::create(['code'=>'PKR','name'=>'Rupee']);
  ExchangeRate::create(['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'effective_date'=>'2026-11-01','rate'=>'65.125000000000','created_by'=>$admin->id]);
  $this->actingAs($admin)->get(route('admin.exchange-rates.index'))->assertOk()->assertSee('Currency Pair Rate Board')->assertSee('fx-new-currency')->assertSee('data-from="'.$usd->id.'"',false)->assertSee('data-from="'.$afn->id.'"',false)->assertSee('65.125');
 }

 public function test_card_rate_save_returns_json_and_updates_only_requested_direction():void {
  $admin=$this->admin();$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_base'=>true]);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani']);
  $payload=['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'rate'=>'65.250000000000','effective_date'=>'2026-11-02','source'=>'UAT'];
  $this->actingAs($admin)->postJson(route('admin.exchange-rates.store'),$payload)->assertCreated()
   ->assertJsonPath('current.rate','65.250000000000')->assertJsonPath('current.effective_date','2026-11-02')
   ->assertJsonPath('rate.from_currency_id',$usd->id)->assertJsonPath('rate.to_currency_id',$afn->id);
  $this->assertDatabaseCount('exchange_rates',1);
  $this->actingAs($admin)->postJson(route('admin.exchange-rates.store'),array_merge($payload,['rate'=>'64.000000000000','effective_date'=>'2026-10-01']))
   ->assertCreated()->assertJsonPath('current.rate','65.250000000000')->assertJsonPath('rate.rate','64.000000000000');
  $this->actingAs($admin)->postJson(route('admin.exchange-rates.store'),array_merge($payload,['from_currency_id'=>$afn->id,'to_currency_id'=>$usd->id,'rate'=>'0.015320000000']))
   ->assertCreated()->assertJsonPath('current.rate','0.015320000000');
  $this->assertDatabaseCount('exchange_rates',3);
  $this->assertDatabaseHas('activity_logs',['action'=>'exchange_rate.recorded']);
 }
 public function test_exchange_page_has_inline_rate_cards_without_automatic_create_dialog():void {
  $admin=$this->admin();Currency::create(['code'=>'USD','name'=>'Dollar','is_base'=>true]);Currency::create(['code'=>'AFN','name'=>'Afghani']);
  $response=$this->actingAs($admin)->get(route('admin.exchange-rates.index'))->assertOk()
   ->assertSee('fx-inline-form')->assertSee('fx-pair-set')->assertSee('fx-add-currency')
   ->assertDontSee('data-new-entry-form=\"1\"', false)->assertDontSee('class=\"rate-grid\"');
  $this->assertSame(2,substr_count($response->getContent(),'class="fx-inline-form"'));
 }
 public function test_rate_card_json_validation_rejects_invalid_without_record():void {
  $admin=$this->admin();$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_base'=>true]);
  $this->actingAs($admin)->postJson(route('admin.exchange-rates.store'),['from_currency_id'=>$usd->id,'to_currency_id'=>$usd->id,'rate'=>'0','effective_date'=>'2026-11-03'])
   ->assertUnprocessable()->assertJsonValidationErrors(['to_currency_id','rate']);
  $this->assertDatabaseCount('exchange_rates',0);
 }

}
