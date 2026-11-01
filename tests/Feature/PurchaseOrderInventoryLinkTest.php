<?php

namespace Tests\Feature;

use App\Models\{Currency,InventoryMaterial,PurchaseOrder,TradeParty,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseOrderInventoryLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_items_can_link_active_materials_without_posting_stock(): void
    {
        $user=User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        Permission::findOrCreate('purchases.update','web');
        $user->givePermissionTo(['administration.view','purchases.update']);
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Supplier','code'=>'SUP-LINK','is_active'=>true,'created_by'=>$user->id]);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $material=InventoryMaterial::create(['sku'=>'RM-LINK','name'=>'Flour','unit'=>'kg']);
        $payload=['supplier_id'=>$supplier->id,'currency_id'=>$currency->id,'source'=>'local','order_date'=>'2026-11-01',
            'items'=>[['description'=>'Flour 10 kg','quantity'=>'10.0000','unit_price'=>'100.0000','inventory_material_id'=>$material->id]]];
        $this->actingAs($user)->post(route('purchases.orders.store'),$payload)->assertRedirect();
        $order=PurchaseOrder::firstOrFail();
        $item=$order->items()->firstOrFail();
        $this->assertSame($material->id,$item->inventory_material_id);
        $this->assertSame('0.000000',$material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements',0);
        $this->actingAs($user)->post(route('purchases.orders.confirm',$order))->assertRedirect();
        $this->assertSame('confirmed',$order->fresh()->status);
        $this->actingAs($user)->patch(route('purchases.orders.items.link',[$order,$item]),['inventory_material_id'=>null])->assertStatus(422);
    }

    public function test_only_authorized_users_can_link_items_and_confirm_orders(): void
    {
        $user=User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        $user->givePermissionTo('administration.view');
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Supplier','code'=>'SUP-RESTRICT','is_active'=>true,'created_by'=>$user->id]);
        $currency=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true,'is_base'=>true]);
        $order=PurchaseOrder::create(['number'=>'PO-RESTRICT','supplier_id'=>$supplier->id,'currency_id'=>$currency->id,'source'=>'local','order_date'=>'2026-11-01','created_by'=>$user->id]);
        $item=$order->items()->create(['description'=>'Flour','quantity'=>1,'unit_price'=>1]);
        $this->actingAs($user)->post(route('purchases.orders.confirm',$order))->assertForbidden();
        $this->actingAs($user)->patch(route('purchases.orders.items.link',[$order,$item]),['inventory_material_id'=>null])->assertForbidden();
        $this->assertSame('draft',$order->fresh()->status);
    }
}
