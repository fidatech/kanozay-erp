<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarehouseStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures():array
    {
        foreach(['inventory.view','inventory.update'] as $p)Permission::findOrCreate($p,'web');
        $user=User::factory()->create();
        $user->givePermissionTo(['inventory.view','inventory.update']);
        $branch=CompanyBranch::create(['code'=>'MAIN','name'=>'Main branch','is_active'=>true]);
        $a=CompanyWarehouse::create(['company_branch_id'=>$branch->id,'code'=>'WH-A','name'=>'Warehouse A','is_active'=>true]);
        $b=CompanyWarehouse::create(['company_branch_id'=>$branch->id,'code'=>'WH-B','name'=>'Warehouse B','is_active'=>true]);
        $m=InventoryMaterial::create(['sku'=>'WHMAT-1','name'=>'Sample flour','unit'=>'kg']);
        // Stock enters through the real ledger so a FIFO layer with a verified
        // (unassigned) lot position exists; warehouse moves reject legacy stock
        // that has no reconciled lot location.
        app(\App\Services\InventoryStockService::class)->post($m,[
            'type'=>'opening','direction'=>'in','quantity'=>'10.000000',
            'reference_type'=>'opening','reference_id'=>$m->id,
            'occurred_on'=>now()->subDays(3)->toDateString(),'created_by'=>$user->id,
        ]);
        return [$user,$a,$b,$m->fresh()];
    }

    private function payload($material,$to,$qty,$type='initial_allocation',$source=null):array
    {
        return [
            'type'=>$type,'inventory_material_id'=>$material->id,
            'destination_warehouse_id'=>$to->id,'source_warehouse_id'=>$source?->id,
            'quantity'=>$qty,'idempotency_key'=>(string)Str::uuid(),
            'occurred_on'=>now()->toDateString(),'reason'=>'Synthetic physical stock allocation',
        ];
    }

    public function test_warehouse_allocations_and_transfers_reconcile_without_changing_global_stock():void
    {
        [$user,$a,$b,$material]=$this->fixtures();
        $this->actingAs($user)->get(route('workspace.page',['inventory','transfers']))->assertOk();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($material,$a,'8'))->assertRedirect();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($material,$a,'3'))->assertSessionHasErrors('quantity');
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($material,$b,'4','transfer',$a))->assertRedirect();
        $this->assertSame('10.000000',$material->fresh()->on_hand_quantity);
        $this->assertEquals(4,DB::table('inventory_warehouse_balances')->where('company_warehouse_id',$a->id)->value('quantity'));
        $this->assertEquals(4,DB::table('inventory_warehouse_balances')->where('company_warehouse_id',$b->id)->value('quantity'));
        $this->assertDatabaseCount('inventory_warehouse_movements',2);
        // Only the fixture's opening receipt: allocations and transfers never post
        // a second global stock movement.
        $this->assertDatabaseCount('inventory_stock_movements',1);
        // The single FIFO lot is positioned 4 at A, 4 at B and 2 still unassigned,
        // and its remaining quantity and cost layer are untouched.
        $layer=DB::table('inventory_cost_layers')->where('inventory_material_id',$material->id)->first();
        $this->assertEquals(10,$layer->remaining_quantity);
        $positions=DB::table('inventory_cost_layer_locations')->where('inventory_cost_layer_id',$layer->id)->pluck('quantity','location_key');
        $this->assertEquals(4,$positions['wh:'.$a->id]);
        $this->assertEquals(4,$positions['wh:'.$b->id]);
        $this->assertEquals(2,$positions['unassigned']);
        $this->assertDatabaseCount('inventory_cost_layers',1);
    }

    public function test_transfer_is_blocked_during_global_production_reservation():void
    {
        [$user,$a,$b,$material]=$this->fixtures();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($material,$a,'10'))->assertRedirect();
        $material->forceFill(['reserved_quantity'=>'2.000000'])->save();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($material,$b,'3','transfer',$a))
            ->assertSessionHasErrors('stock');
        $this->assertDatabaseCount('inventory_warehouse_movements',1);
    }

    public function test_duplicate_token_is_safe_and_unrelated_warehouse_request_is_blocked():void
    {
        [$user,$a,$b,$material]=$this->fixtures();
        $payload=$this->payload($material,$a,'5');
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$payload)->assertRedirect();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$payload)->assertRedirect();
        $payload['destination_warehouse_id']=$b->id;
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$payload)->assertSessionHasErrors('idempotency_key');
        $this->assertDatabaseCount('inventory_warehouse_movements',1);
        $outsider=User::factory()->create();
        $this->actingAs($outsider)->get(route('inventory.warehouse-stock.index'))->assertForbidden();
        $this->actingAs($outsider)->post(route('inventory.warehouse-stock.store'),[])->assertForbidden();
    }

    public function test_legacy_stock_without_a_reconciled_fifo_lot_cannot_be_allocated():void
    {
        [$user,$a]=$this->fixtures();
        $legacy=InventoryMaterial::create(['sku'=>'WHMAT-LEGACY','name'=>'Legacy unpositioned stock','unit'=>'kg']);
        $legacy->forceFill(['on_hand_quantity'=>'5.000000'])->save();
        $this->actingAs($user)->post(route('inventory.warehouse-stock.store'),$this->payload($legacy,$a,'5'))
            ->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('inventory_warehouse_movements',0);
        $this->assertDatabaseMissing('inventory_warehouse_balances',['inventory_material_id'=>$legacy->id]);
    }
}
