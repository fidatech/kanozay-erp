<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\ProcurementRequest;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProcurementRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function records():array
    {
        foreach(['inventory.view','inventory.create','inventory.update','purchases.view',
            'purchases.create','purchases.update'] as $p)Permission::findOrCreate($p,'web');
        $requester=User::factory()->create();
        $requester->givePermissionTo(['inventory.view','inventory.create','inventory.update']);
        $reviewer=User::factory()->create();$reviewer->givePermissionTo(['purchases.view','purchases.update']);
        $buyer=User::factory()->create();$buyer->givePermissionTo(['purchases.create','purchases.view','inventory.view']);
        $material=InventoryMaterial::create(['sku'=>'RM-REQ-1','name'=>'Synthetic powder','unit'=>'kg']);
        $material->forceFill(['on_hand_quantity'=>'3.000000'])->save();
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Test supplier','is_active'=>true,'created_by'=>$buyer->id]);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        return [$requester,$reviewer,$buyer,$material,$supplier,$currency];
    }

    public function test_reorder_rule_submission_independent_approval_and_po_conversion():void
    {
        [$requester,$reviewer,$buyer,$material,$supplier,$currency]=$this->records();
        $this->actingAs($requester)->post(route('procurement.rules.store'),[
            'inventory_material_id'=>$material->id,'minimum_quantity'=>'5',
            'target_quantity'=>'20','lead_days'=>7,'supplier_id'=>$supplier->id,
        ])->assertRedirect();
        $this->actingAs($requester)->get(route('workspace.page',['inventory','reorder']))
            ->assertOk()->assertSee('17.000000');
        $payload=['submission_token'=>(string)Str::uuid(),'requested_on'=>today()->toDateString(),
            'lines'=>[['inventory_material_id'=>$material->id,'quantity'=>'17','description'=>'Replenish synthetic material']]];
        $this->actingAs($requester)->post(route('procurement.requests.store'),$payload)->assertRedirect();
        $req=ProcurementRequest::firstOrFail();
        $this->assertSame('submitted',$req->status);
        $this->actingAs($requester)->post(route('procurement.requests.decide',$req),['decision'=>'approved'])->assertForbidden();
        $this->actingAs($reviewer)->post(route('procurement.requests.decide',$req),['decision'=>'approved'])->assertRedirect();
        $this->assertSame('approved',$req->fresh()->status);
        $priceLine=$req->lines()->firstOrFail();
        $conversion=['supplier_id'=>$supplier->id,'currency_id'=>$currency->id,
            'source'=>'local','order_date'=>today()->toDateString(),'unit_prices'=>[$priceLine->id=>'12.5000']];
        $this->actingAs($buyer)->post(route('procurement.requests.convert',$req),$conversion)
            ->assertRedirect();
        $po=PurchaseOrder::firstOrFail();
        $this->assertSame('draft',$po->status);
        $this->assertSame($material->id,$po->items()->firstOrFail()->inventory_material_id);
        $this->assertSame('12.5000',sprintf('%.4f',(float)$po->items()->firstOrFail()->unit_price));
        $this->assertSame('converted',$req->fresh()->status);
        $this->actingAs($buyer)->post(route('procurement.requests.convert',$req),$conversion)
            ->assertSessionHasErrors('status');
        $this->assertDatabaseCount('purchase_orders',1);
    }

    public function test_duplicate_material_lines_and_unknown_access_are_rejected():void
    {
        [$requester,,$buyer,$material]=$this->records();
        $payload=['submission_token'=>(string)Str::uuid(),'requested_on'=>today()->toDateString(),
            'lines'=>[
                ['inventory_material_id'=>$material->id,'quantity'=>'1'],
                ['inventory_material_id'=>$material->id,'quantity'=>'2'],
            ]];
        $this->actingAs($requester)->post(route('procurement.requests.store'),$payload)
            ->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('procurement_requests',0);
        $unprivileged=User::factory()->create();
        $this->actingAs($unprivileged)->get(route('workspace.page',['purchases','requisitions']))
            ->assertForbidden();
    }
}
