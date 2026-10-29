<?php
namespace Tests\Feature;

use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionRecipeWorkflowTest extends TestCase {
 use RefreshDatabase;
 public function test_recipe_creation_requires_permission_and_keeps_draft_status(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.view','web');
  Permission::findOrCreate('administration.update','web');
  $payload=['name'=>'Test Liquid Formula','method'=>'liquid_blending','package_weight_kg'=>20,'package_unit'=>'can','lines'=>[['material_name'=>'Water','unit'=>'kg','quantity_per_package'=>5.125]]];
  $this->actingAs($user)->get(route('production.recipes.index'))->assertForbidden();
  $this->actingAs($user)->post(route('production.recipes.store'),$payload)->assertForbidden();
  $user->givePermissionTo('administration.view');
  $this->actingAs($user)->get(route('production.recipes.index'))->assertOk();
  $user->givePermissionTo('administration.update');
  $this->actingAs($user)->post(route('production.recipes.store'),array_replace_recursive($payload,['lines'=>[['quantity_per_package'=>-1]]]))->assertSessionHasErrors('lines.0.quantity_per_package');
  $this->actingAs($user)->post(route('production.recipes.store'),$payload)->assertRedirect(route('production.recipes.index'));
  $recipe=ProductionRecipe::firstOrFail();
  $this->assertSame('draft',$recipe->status);
  $this->assertCount(1,$recipe->lines);
 }
}
