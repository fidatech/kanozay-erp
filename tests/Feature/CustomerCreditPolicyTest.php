<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\CustomerCreditPolicy;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class CustomerCreditPolicyTest extends TestCase {
 use RefreshDatabase;
 public function test_customer_credit_policy_is_enforced_and_can_be_disabled():void {
  Permission::findOrCreate('finance.update','web');
  Permission::findOrCreate('finance.view','web');
  $user=User::factory()->create();$user->givePermissionTo(['finance.update','finance.view']);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $party=TradeParty::create(['type'=>'customer','name'=>'Test credit customer','is_active'=>true,'created_by'=>$user->id]);
  $party->currencies()->attach($currency->id);
  $policy=app(CustomerCreditPolicy::class);
  $policy->enforce($party->id,$currency->id,BigDecimal::of('100000'));
  $data=['trade_party_id'=>$party->id,'currency_id'=>$currency->id,'limit_amount'=>'100',
   'is_active'=>'1','approval_reference'=>'BOARD-2026'];
  $this->actingAs($user)->post(route('sales.credit-limits.store'),$data)->assertRedirect();
  $policy->enforce($party->id,$currency->id,BigDecimal::of('100'));
  try{$policy->enforce($party->id,$currency->id,BigDecimal::of('100.0001'));$this->fail('Limit not enforced');}
  catch(ValidationException $e){$this->assertArrayHasKey('invoice',$e->errors());}
  $this->actingAs($user)->get(route('workspace.page',['sales','customer-credit-limits']))
    ->assertOk()->assertSee('BOARD-2026');
  $data['is_active']='0';
  $this->actingAs($user)->post(route('sales.credit-limits.store'),$data)->assertRedirect();
  $policy->enforce($party->id,$currency->id,BigDecimal::of('100000'));
 }
 public function test_credit_mutation_is_denied_without_finance_permission():void {
  $user=User::factory()->create();
  $this->actingAs($user)->post(route('sales.credit-limits.store'),[])->assertForbidden();
 }
}
