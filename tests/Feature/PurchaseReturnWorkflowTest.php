<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMaterial;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupReceipt():array
    {
        foreach(['purchases.view','purchases.update','inventory.update'] as $p)Permission::findOrCreate($p,'web');
        $user=User::factory()->create();
        $user->givePermissionTo(['purchases.view','purchases.update','inventory.update']);
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Synthetic supplier','is_active'=>true,'created_by'=>$user->id]);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $material=InventoryMaterial::create(['sku'=>'RET-1','name'=>'Synthetic ingredient','unit'=>'kg']);
        $order=PurchaseOrder::create([
            'number'=>'PO-RETURN-1','supplier_id'=>$supplier->id,'currency_id'=>$currency->id,
            'source'=>'local','order_date'=>today()->subWeek()->toDateString(),'status'=>'confirmed','created_by'=>$user->id,
        ]);
        $item=$order->items()->create(['description'=>'Ingredient','quantity'=>'10','unit_price'=>'12.3456','inventory_material_id'=>$material->id]);
        $this->actingAs($user)->post(route('purchases.orders.receipts.store',$order),[
            'receipt_token'=>(string)Str::uuid(),'received_on'=>today()->subDays(3)->toDateString(),
            'quantities'=>[$item->id=>'10.000000'],
        ])->assertRedirect();
        $receipt=GoodsReceipt::firstOrFail();
        $line=GoodsReceiptLine::firstOrFail();
        return [$user,$material,$order,$receipt,$line];
    }

    private function payload(GoodsReceipt $receipt,GoodsReceiptLine $line,string $quantity):array
    {
        return [
            'goods_receipt_id'=>$receipt->id,'submission_token'=>(string)Str::uuid(),
            'returned_on'=>today()->toDateString(),'reason'=>'Damaged shipment returned after verification',
            'quantities'=>[$line->id=>$quantity],
        ];
    }

    public function test_return_deducts_stock_without_modifying_original_receipt_or_inventing_credit():void
    {
        [$user,$material,$order,$receipt,$line]=$this->setupReceipt();
        $this->actingAs($user)->get(route('workspace.page',['purchases','purchase-returns']))->assertOk();
        $payload=$this->payload($receipt,$line,'4');
        $this->actingAs($user)->post(route('purchases.returns.store'),$payload)->assertRedirect();
        $this->assertDatabaseCount('purchase_return_records',1);
        $this->assertSame('6.000000',$material->fresh()->on_hand_quantity);
        $this->assertSame('10.000000',$line->fresh()->quantity);
        $this->assertDatabaseHas('purchase_return_records',['status'=>'posted_pending_credit','financial_status'=>'supplier_credit_pending']);
        $this->assertDatabaseCount('journal_entries',0);
        $this->actingAs($user)->post(route('purchases.returns.store'),$payload)->assertRedirect();
        $this->assertDatabaseCount('purchase_return_records',1);
        $payload['submission_token']=(string)Str::uuid();
        $payload['quantities'][$line->id]='7';
        $this->actingAs($user)->post(route('purchases.returns.store'),$payload)->assertSessionHasErrors('quantities');
        $this->assertSame('6.000000',$material->fresh()->on_hand_quantity);
        $this->actingAs($user)->get(route('purchases.returns.show',\App\Models\PurchaseReturnRecord::first()))
            ->assertOk()->assertSee('Synthetic supplier');
    }

    public function test_purchase_return_requires_joint_procurement_inventory_permission():void
    {
        [,,$order,$receipt,$line]=$this->setupReceipt();
        $outsider=User::factory()->create();
        $this->actingAs($outsider)->get(route('workspace.page',['purchases','purchase-returns']))->assertForbidden();
        $this->actingAs($outsider)->post(route('purchases.returns.store'),$this->payload($receipt,$line,'1'))->assertForbidden();
    }
}
