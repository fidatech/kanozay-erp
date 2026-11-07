<?php
namespace Tests\Feature;
use App\Models\{Currency,FinishedGood,ProductionBatch,ProductionRecipe,ProductionOrder,User};
use App\Services\ProductionCostReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionCostReadinessTest extends TestCase {
 use RefreshDatabase;
 public function test_batch_cost_only_becomes_calculable_when_materials_have_currency_backing():void {
  $user=User::factory()->create();Permission::findOrCreate('manufacturing.view','web');
  $user->givePermissionTo('manufacturing.view');$this->actingAs($user);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $recipe=ProductionRecipe::create(['name'=>'Test recipe','method'=>'powder_blending',
   'package_weight_kg'=>1,'package_unit'=>'bag','status'=>'approved']);
  $order=ProductionOrder::create(['number'=>'PRD-READ-01','production_recipe_id'=>$recipe->id,
   'package_count'=>10,'status'=>'completed']);
  $good=FinishedGood::create(['sku'=>'FG-READ-01','name'=>'Cost source check',
   'package_weight_kg'=>1,'package_unit'=>'bag']);
  $batch=ProductionBatch::create(['production_order_id'=>$order->id,'finished_good_id'=>$good->id,
   'batch_number'=>'BAT-READ-01','reported_packages'=>10]);
  $batch->forceFill(['status'=>'released','accepted_packages'=>10])->save();
  $service=app(ProductionCostReadinessService::class);
  $rows=$service->rows(collect([$batch->load(['costs','finishedGood'])]));
  $this->assertFalse($rows->first()['ready']);
  $this->assertNull($rows->first()['cost_per_package']);

  $batch->costs()->create(['currency_id'=>$currency->id,'currency_code'=>'AFN',
   'material_cost'=>'100.0000000000','consumed_quantity'=>'10.000000','unvalued_quantity'=>'0.000000']);
  $row=$service->rows(collect([$batch->fresh()->load(['costs','finishedGood'])]))->first();
  $this->assertTrue($row['ready']);
  $this->assertSame('10.00000000',$row['cost_per_package']);
  $this->actingAs($user)->get(route('manufacturing.cost-readiness.index'))->assertOk()
   ->assertSee('BAT-READ-01');
  $this->actingAs($user)->get(route('manufacturing.cost-readiness.export'))->assertOk()
   ->assertHeader('Content-Type','text/csv; charset=UTF-8');
 }
}