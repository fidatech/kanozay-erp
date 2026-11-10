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
  $this->assertSame('PRD-'.str_pad((string)$order->id,6,'0',STR_PAD_LEFT),$order->number);
  $this->assertDatabaseHas('production_order_materials',['production_order_id'=>$order->id,'material_name'=>'Dextrose','required_quantity'=>50]);
  $this->actingAs($user)->get(route('production.orders.show',$order))->assertOk()->assertSee('50.000000')->assertSee('Not mapped');
 }
 public function test_material_requirements_are_immutable_after_recipe_changes(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo(['administration.view','administration.update']);
  $recipe=ProductionRecipe::create(['name'=>'Stable formula','method'=>'powder_blending','package_weight_kg'=>25,'package_unit'=>'bag','status'=>'approved']);
  $line=$recipe->lines()->create(['line_number'=>1,'material_name'=>'Original material','unit'=>'kg','quantity_per_package'=>'0.000001']);
  $this->actingAs($user)->post(route('production.orders.store'),['production_recipe_id'=>$recipe->id,'package_count'=>100000])->assertRedirect();
  $order=ProductionOrder::firstOrFail();
  $this->assertSame('0.100000',(string) $order->materials()->firstOrFail()->required_quantity);
  $line->update(['material_name'=>'Changed material','quantity_per_package'=>9]);
  $this->actingAs($user)->get(route('production.orders.show',$order))
    ->assertOk()->assertSee('Original material')->assertSee('0.100000')->assertDontSee('Changed material');
 }
 public function test_approved_recipe_without_material_lines_cannot_create_plan(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo('administration.update');
  $recipe=ProductionRecipe::create(['name'=>'Empty formula','method'=>'powder_blending','package_weight_kg'=>25,'package_unit'=>'bag','status'=>'approved']);
  $this->actingAs($user)->post(route('production.orders.store'),['production_recipe_id'=>$recipe->id,'package_count'=>1])->assertStatus(422);
  $this->assertSame(0,ProductionOrder::count());
 }
 public function test_legacy_production_plan_displays_missing_snapshot_warning(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  $user->givePermissionTo('administration.view');
  $recipe=ProductionRecipe::create(['name'=>'Legacy formula','method'=>'powder_blending','package_weight_kg'=>25,'package_unit'=>'bag','status'=>'approved']);
  $order=ProductionOrder::create(['number'=>'PRD-000001','production_recipe_id'=>$recipe->id,'package_count'=>1,'status'=>'planned']);
  $this->actingAs($user)->get(route('production.orders.show',$order))->assertOk()->assertSee('No saved material requirements');
 }
 public function test_unauthorized_user_cannot_create_production_plan(): void {
  $user=User::factory()->create();
  $this->actingAs($user)->post(route('production.orders.store'),['production_recipe_id'=>1,'package_count'=>1])->assertForbidden();
 }
}
