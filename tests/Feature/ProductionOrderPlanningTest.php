<?php
namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionOrderPlanningTest extends TestCase {
 use RefreshDatabase;
 public function test_only_approved_recipe_can_create_a_plan_and_materials_scale(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo(['administration.view','administration.update']);
  $recipe=ProductionRecipe::create(['name'=>'Powder formula','method'=>'powder_blending','package_weight_kg'=>25,'package_unit'=>'bag','status'=>'draft']);
  $recipe->lines()->create(['line_number'=>1,'material_name'=>'Dextrose','unit'=>'kg','quantity_per_package'=>12.5]);
  $payload=['production_recipe_id'=>$recipe->id,'package_count'=>4];
  $this->actingAs($user)->post(route('production.orders.store'),$payload)->assertSessionHasErrors('production_recipe_id');
  $this->assertSame(0,ProductionOrder::count());
  $recipe->update(['status'=>'approved']);
  $this->actingAs($user)->post(route('production.orders.store'),$payload)->assertRedirect();
  $order=ProductionOrder::firstOrFail();
  $this->assertSame('planned',$order->status);
  $this->assertSame('PRD-000001',$order->number);
  $this->actingAs($user)->get(route('production.orders.show',$order))->assertOk()->assertSee('50.000000')->assertSee('Not yet connected');
 }
 public function test_unauthorized_user_cannot_create_production_plan(): void {
  $user=User::factory()->create();
  $this->actingAs($user)->post(route('production.orders.store'),['production_recipe_id'=>1,'package_count'=>1])->assertForbidden();
 }
}
