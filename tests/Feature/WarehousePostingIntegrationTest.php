<?php
namespace Tests\Feature;
use App\Models\CompanyBranch;
use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class WarehousePostingIntegrationTest extends TestCase {
 use RefreshDatabase;
 public function test_warehouse_specific_opening_and_issue_preserve_global_reconciliation():void {
  $user=User::factory()->create();
  $branch=CompanyBranch::create(['code'=>'B-TEST','name'=>'Test branch','is_active'=>true]);
  $wh=CompanyWarehouse::create(['company_branch_id'=>$branch->id,'code'=>'WH-TEST','name'=>'Test warehouse','is_active'=>true]);
  $material=InventoryMaterial::create(['sku'=>'RM-WHT','name'=>'Test raw material','unit'=>'kg']);
  $service=app(InventoryStockService::class);
  $service->post($material,[
   'type'=>'opening','direction'=>'in','quantity'=>'12',
   'reference_type'=>'opening','reference_id'=>$material->id,
   'company_warehouse_id'=>$wh->id,'occurred_on'=>today()->toDateString(),'created_by'=>$user->id,
  ]);
  $this->assertSame('12.000000',$material->fresh()->on_hand_quantity);
  $this->assertEquals(12,DB::table('inventory_warehouse_balances')->where('company_warehouse_id',$wh->id)->value('quantity'));
  try {
   $service->post($material,['type'=>'issue','direction'=>'out','quantity'=>'1',
    'reference_type'=>'synthetic_issue','reference_id'=>99,
    'occurred_on'=>today()->toDateString(),'created_by'=>$user->id]);
   $this->fail('Issue without warehouse should fail when no unassigned stock is available.');
  }catch(ValidationException $e){$this->assertArrayHasKey('company_warehouse_id',$e->errors());}
  $service->post($material,[
   'type'=>'issue','direction'=>'out','quantity'=>'4',
   'reference_type'=>'synthetic_issue','reference_id'=>99,
   'company_warehouse_id'=>$wh->id,'occurred_on'=>today()->toDateString(),'created_by'=>$user->id,
  ]);
  $this->assertSame('8.000000',$material->fresh()->on_hand_quantity);
  $this->assertEquals(8,DB::table('inventory_warehouse_balances')->where('company_warehouse_id',$wh->id)->value('quantity'));
  $this->assertDatabaseCount('inventory_stock_movements',2);
 }
}
