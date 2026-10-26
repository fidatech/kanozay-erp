<?php
namespace Tests\Feature;
use App\Models\{Currency,PurchaseOrder,TradeParty,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class PurchaseOrderTest extends TestCase {
 use RefreshDatabase;
 public function test_authorized_user_can_create_draft_purchase_order_with_items():void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  $user->givePermissionTo('administration.view');
  $supplier=TradeParty::create(['type'=>'supplier','name'=>'Test Supplier','code'=>'SUP-001','is_active'=>true,'created_by'=>$user->id]);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','symbol'=>'؋','decimal_places'=>2,'is_active'=>true,'is_base'=>true]);
  $payload=['number'=>'PO-TEST-1','supplier_id'=>$supplier->id,'currency_id'=>$currency->id,'source'=>'foreign','order_date'=>'2026-10-27','items'=>[['description'=>'Flour','quantity'=>10,'unit_price'=>200]]];
  $this->actingAs($user)->post(route('purchases.orders.store'),$payload)->assertRedirect(route('purchases.orders.index'));
  $order=PurchaseOrder::firstOrFail();
  $this->assertSame('draft',$order->status);
  $this->assertSame('foreign',$order->source);
  $this->assertSame('Flour',$order->items()->firstOrFail()->description);
  $this->actingAs($user)->get(route('purchases.orders.show',$order))->assertOk()->assertSee('Flour');
  $this->actingAs($user)->post(route('purchases.orders.store'),$payload)->assertSessionHasErrors('number');
  $this->assertSame(1,PurchaseOrder::count());
 }
 public function test_expenses_and_advances_are_recorded_on_order_without_finance_postings():void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  $user->givePermissionTo('administration.view');
  $supplier=TradeParty::create(['type'=>'supplier','name'=>'Supplier Two','code'=>'SUP-002','is_active'=>true,'created_by'=>$user->id]);
  $currency=Currency::create(['code'=>'USD','name'=>'US Dollar','symbol'=>'$','decimal_places'=>2,'is_active'=>true,'is_base'=>true]);
  $order=PurchaseOrder::create(['number'=>'PO-COST-1','supplier_id'=>$supplier->id,'currency_id'=>$currency->id,'source'=>'foreign','order_date'=>'2026-10-27','status'=>'draft','created_by'=>$user->id]);
  $order->items()->create(['description'=>'Material','quantity'=>10,'unit_price'=>100]);
  $this->actingAs($user)->post(route('purchases.orders.expenses.store',$order),['category'=>'customs','amount'=>100,'expense_date'=>'2026-10-27'])->assertRedirect();
  $this->actingAs($user)->post(route('purchases.orders.advances.store',$order),['amount'=>250,'payment_date'=>'2026-10-27','reference'=>'TX-01'])->assertRedirect();
  $this->assertSame(1,$order->expenses()->count());
  $this->assertSame(1,$order->advances()->count());
  $this->actingAs($user)->get(route('purchases.orders.show',$order))->assertOk()->assertSee('1,100.00')->assertSee('750.00')->assertSee('TX-01');
  $this->actingAs($user)->post(route('purchases.orders.expenses.store',$order),['category'=>'invalid','amount'=>100,'expense_date'=>'2026-10-27'])->assertSessionHasErrors('category');
  $order->update(['status'=>'confirmed']);
  $this->actingAs($user)->post(route('purchases.orders.advances.store',$order),['amount'=>10,'payment_date'=>'2026-10-27'])->assertStatus(422);
 }
 public function test_unauthorized_user_cannot_create_orders():void {
  $user=User::factory()->create();
  $this->actingAs($user)->get(route('purchases.orders.index'))->assertForbidden();
  $this->actingAs($user)->post(route('purchases.orders.store'),[])->assertForbidden();
 }
}
