<?php

namespace Tests\Feature;

use App\Models\{Currency,GoodsReceipt,InventoryMaterial,PurchaseOrder,TradeParty,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithMaterial(): array
    {
        $user=User::factory()->create();
        Permission::findOrCreate('inventory.update','web');
        Permission::findOrCreate('administration.view','web');
        $user->givePermissionTo(['inventory.update','administration.view']);
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'GRN Supplier','code'=>'SUP-GRN','is_active'=>true,'created_by'=>$user->id]);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $material=InventoryMaterial::create(['sku'=>'GRN-FLOUR','name'=>'Flour','unit'=>'kg']);
        $order=PurchaseOrder::create(['number'=>'PO-GRN-1','supplier_id'=>$supplier->id,'currency_id'=>$currency->id,
            'source'=>'local','order_date'=>'2026-11-01','status'=>'confirmed','created_by'=>$user->id]);
        $item=$order->items()->create(['description'=>'Flour','quantity'=>'10.0000','unit_price'=>'100.0000','inventory_material_id'=>$material->id]);
        return [$user,$order,$item,$material];
    }

    private function payload(int $itemId, string $quantity, string $token): array
    {
        return ['receipt_token'=>$token,'received_on'=>'2026-11-01','supplier_reference'=>'DN-1',
            'quantities'=>[$itemId=>$quantity]];
    }

    public function test_partial_and_final_receipts_post_exact_stock_and_close_order(): void
    {
        [$user,$order,$item,$material]=$this->orderWithMaterial();
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'3.125000',(string)Str::uuid()))->assertRedirect();
        $this->assertSame('3.125000',$material->fresh()->on_hand_quantity);
        $this->assertSame('partially_received',$order->fresh()->status);
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'6.875000',(string)Str::uuid()))->assertRedirect();
        $this->assertSame('10.000000',$material->fresh()->on_hand_quantity);
        $this->assertSame('received',$order->fresh()->status);
        $this->assertDatabaseCount('goods_receipts',2);
        $this->assertDatabaseCount('inventory_stock_movements',2);
        // The GRN number derives from the receipt id (ids are not reset on MySQL).
        $first=GoodsReceipt::orderBy('id')->firstOrFail();
        $this->assertSame('GRN-'.str_pad((string)$first->id,6,'0',STR_PAD_LEFT),$first->number);
        $this->assertSame(2,GoodsReceipt::distinct()->count('number'));
        $this->actingAs($user)->get(route('purchases.orders.show',$order))->assertOk()->assertSee($first->number);
    }

    public function test_duplicate_token_and_over_receipt_cannot_double_post(): void
    {
        [$user,$order,$item,$material]=$this->orderWithMaterial();
        $token=(string)Str::uuid();
        $payload=$this->payload($item->id,'4.000000',$token);
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),$payload)->assertRedirect();
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),$payload)
            ->assertSessionHasErrors('receipt_token');
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'7.000000',(string)Str::uuid()))->assertSessionHasErrors('quantities');
        $this->assertSame('4.000000',$material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('goods_receipts',1);
        $this->assertDatabaseCount('inventory_stock_movements',1);
    }

    public function test_inactive_material_causes_complete_receipt_rollback(): void
    {
        [$user,$order,$item,$material]=$this->orderWithMaterial();
        $material->update(['is_active'=>false]);
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'1.000000',(string)Str::uuid()))
            ->assertSessionHasErrors('inventory_material_id');
        $this->assertDatabaseCount('goods_receipts',0);
        $this->assertDatabaseCount('goods_receipt_lines',0);
        $this->assertDatabaseCount('inventory_stock_movements',0);
        $this->assertSame('confirmed',$order->fresh()->status);
    }

    public function test_draft_order_and_user_without_inventory_permission_cannot_receive(): void
    {
        [$user,$order,$item,$material]=$this->orderWithMaterial();
        $user->revokePermissionTo('inventory.update');
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'1',(string)Str::uuid()))->assertForbidden();
        $user->givePermissionTo('inventory.update');
        $order->update(['status'=>'draft']);
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),
            $this->payload($item->id,'1',(string)Str::uuid()))->assertSessionHasErrors('purchase_order_id');
        $this->assertDatabaseCount('goods_receipts',0);
        $this->assertSame('0.000000',$material->fresh()->on_hand_quantity);
    }
}
