<?php
namespace Tests\Feature;

use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class KanozayWorkbookPreviewTest extends TestCase {
 use RefreshDatabase;
 public function test_original_workbook_preview_detects_source_discrepancies_without_import(): void {
  $path='/Users/mastaan/Downloads/Production Types and Recipes Formula.xlsx';
  if (!is_file($path)) $this->markTestSkipped('Original Kanozay workbook not on this machine.');
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo('administration.update');
  $file=new UploadedFile($path,'Production Types and Recipes Formula.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
  $this->actingAs($user)->post(route('production.recipes.kanozay-preview'),['file'=>$file])->assertOk()->assertSee('Anar Concentrate KC786')->assertSee('Prime Glucose KS1250')->assertSee('nonpositive quantity')->assertSee('26.11');
  $this->actingAs($user)->post(route('production.recipes.kanozay-confirm'))->assertRedirect(route('production.recipes.index'))->assertSessionHasErrors('file');
  $this->assertSame(0,ProductionRecipe::count());
 }
 public function test_clean_preview_snapshot_can_only_be_confirmed_once_as_drafts(): void {
  $user=User::factory()->create();
  Permission::findOrCreate('administration.update','web');
  $user->givePermissionTo('administration.update');
  $recipe=['name'=>'Test formula','sheet'=>'Production Method 1','method'=>'liquid_blending','weight'=>20,'package_unit'=>'can','warnings'=>[],'lines'=>[['material'=>'Water','unit'=>'kg','quantity'=>20,'unit_cost'=>0,'cell'=>'F4']]];
  $this->actingAs($user)->withSession(['kanozay_recipe_preview'=>[$recipe,array_replace($recipe,['name'=>'Second formula','sheet'=>'Production Method 2'])]])->post(route('production.recipes.kanozay-confirm'))->assertRedirect(route('production.recipes.index'));
  $this->assertSame(2,ProductionRecipe::count());
  $this->assertSame(2,ProductionRecipe::where('status','draft')->count());
  $this->actingAs($user)->post(route('production.recipes.kanozay-confirm'))->assertSessionHasErrors('file');
  $this->assertSame(2,ProductionRecipe::count());
 }

}
