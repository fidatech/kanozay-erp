<?php
namespace Tests\Feature;

use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionRecipeImportPreviewTest extends TestCase {
 use RefreshDatabase;
 public function test_preview_detects_weight_mismatch_without_writing_recipes(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo('administration.update');
  $csv="recipe_name,method,package_weight_kg,package_unit,material_name,unit,quantity_per_package,unit_cost,source_sheet,source_cell\nPrime Glucose,powder_blending,25,bag,Dextrose,kg,11.75,3.3,Production Method 2,F6\n";
  $file=UploadedFile::fake()->createWithContent('recipes.csv',$csv);
  $this->actingAs($user)->post(route('production.recipes.import-preview'),['file'=>$file])->assertOk()->assertSee('differs from package weight')->assertSee('Prime Glucose');
  $this->assertSame(0,ProductionRecipe::count());
 }
 public function test_preview_rejects_unauthorized_user(): void {
  $user=User::factory()->create();
  $this->actingAs($user)->post(route('production.recipes.import-preview'),['file'=>UploadedFile::fake()->createWithContent('recipes.csv','bad')])->assertForbidden();
 }
}
