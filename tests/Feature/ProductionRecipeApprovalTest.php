<?php
namespace Tests\Feature;

use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionRecipeApprovalTest extends TestCase {
 use RefreshDatabase;
 private function recipe(float $quantity): ProductionRecipe {
  $recipe=ProductionRecipe::create(['name'=>'Test formula','method'=>'powder_blending','package_weight_kg'=>25,'package_unit'=>'bag','status'=>'draft']);
  $recipe->lines()->create(['line_number'=>1,'material_name'=>'Dextrose','unit'=>'kg','quantity_per_package'=>$quantity]);
  return $recipe;
 }
 public function test_only_authorized_user_can_approve_valid_recipe_once(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  Permission::findOrCreate('administration.view','web');
  $recipe=$this->recipe(25);
  $this->actingAs($user)->post(route('production.recipes.approve',$recipe))->assertForbidden();
  $user->givePermissionTo('administration.update');
  $this->actingAs($user)->post(route('production.recipes.approve',$recipe))->assertRedirect(route('production.recipes.show',$recipe));
  $recipe->refresh();
  $this->assertSame('approved',$recipe->status);
  $this->assertSame($user->id,$recipe->approved_by);
  $this->assertNotNull($recipe->approved_at);
  $this->actingAs($user)->post(route('production.recipes.approve',$recipe))->assertSessionHasErrors('approval');
  $user->givePermissionTo('administration.view');
  $this->actingAs($user)->get(route('production.recipes.show',$recipe))->assertOk()->assertSee('Dextrose');
 }
 public function test_weight_tolerance_is_checked_at_six_decimal_precision(): void {
  $user = User::factory()->create();
  Permission::findOrCreate('administration.update', 'web');
  $user->givePermissionTo('administration.update');

  $within = ProductionRecipe::create([
   'name' => 'Boundary formula', 'method' => 'powder_blending',
   'package_weight_kg' => '25.0000', 'package_unit' => 'bag', 'status' => 'draft',
  ]);
  $within->lines()->create([
   'line_number' => 1, 'material_name' => 'Ingredient', 'unit' => 'kg',
   'quantity_per_package' => '25.010000',
  ]);
  $this->actingAs($user)->post(route('production.recipes.approve', $within))->assertRedirect();
  $this->assertSame('approved', $within->fresh()->status);

  $outside = ProductionRecipe::create([
   'name' => 'Outside tolerance', 'method' => 'powder_blending',
   'package_weight_kg' => '25.0000', 'package_unit' => 'bag', 'status' => 'draft',
  ]);
  $outside->lines()->create([
   'line_number' => 1, 'material_name' => 'Ingredient', 'unit' => 'kg',
   'quantity_per_package' => '25.010001',
  ]);
  $this->actingAs($user)->post(route('production.recipes.approve', $outside))->assertSessionHasErrors('approval');
  $this->assertSame('draft', $outside->fresh()->status);
 }
 public function test_mismatched_weight_blocks_approval(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo('administration.update');
  $recipe=$this->recipe(25.11);
  $this->actingAs($user)->post(route('production.recipes.approve',$recipe))->assertSessionHasErrors('approval');
  $this->assertSame('draft',$recipe->fresh()->status);
 }
}
