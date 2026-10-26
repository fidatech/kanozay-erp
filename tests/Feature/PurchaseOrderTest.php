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
 public function test_unauthorized_user_cannot_create_orders():void {
  $user=User::factory()->create();
  $this->actingAs($user)->get(route('purchases.orders.index'))->assertForbidden();
  $this->actingAs($user)->post(route('purchases.orders.store'),[])->assertForbidden();
 }
}
