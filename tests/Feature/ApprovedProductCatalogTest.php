<?php
namespace Tests\Feature;
use App\Models\{InventoryMaterial,OperationalRegister,User};
use App\Services\ApprovedCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class ApprovedProductCatalogTest extends TestCase {
 use RefreshDatabase;
 public function test_only_approved_matching_catalog_units_and_categories_can_be_assigned():void {
  $u=User::factory()->create();
  foreach(['inventory.view','inventory.create','inventory.update'] as $permission)
   Permission::findOrCreate($permission,'web');
  $u->givePermissionTo(['inventory.view','inventory.create','inventory.update']);
  $unit=OperationalRegister::create([
   'workflow'=>'inventory.units','number'=>'UNT-00001','submission_token'=>(string)Str::uuid(),
   'data'=>['symbol'=>'kg','name'=>'Kilogram','precision'=>6,'category'=>'mass'],
   'status'=>'approved','created_by'=>$u->id,'reviewed_by'=>$u->id,
  ]);
  $category=OperationalRegister::create([
   'workflow'=>'inventory.categories','number'=>'CAT-00001','submission_token'=>(string)Str::uuid(),
   'data'=>['code'=>'RAW','name'=>'Raw ingredients','group'=>'raw'],
   'status'=>'approved','created_by'=>$u->id,'reviewed_by'=>$u->id,
  ]);
  $this->actingAs($u)->post(route('inventory.materials.store'),[
   'sku'=>'CAT-MAT-001','name'=>'Synthetic powder','unit'=>'kg','is_packaging'=>0,
   'catalog_unit_id'=>$unit->id,'catalog_category_id'=>$category->id,
  ])->assertRedirect();
  $m=InventoryMaterial::firstOrFail();
  $this->assertSame($unit->id,$m->catalog_unit_id);
  $this->assertSame($category->id,$m->catalog_category_id);
  $this->actingAs($u)->post(route('inventory.materials.catalog',$m),[
   'catalog_unit_id'=>$unit->id,'catalog_category_id'=>$category->id,
  ])->assertRedirect();
  $this->assertSame('kg',$m->fresh()->unit);
  $this->actingAs($u)->post(route('inventory.materials.store'),[
   'sku'=>'CAT-MAT-002','name'=>'Wrong unit','unit'=>'L','is_packaging'=>0,
   'catalog_unit_id'=>$unit->id,
  ])->assertSessionHasErrors('catalog_unit_id');
  $category->update(['status'=>'draft']);
  $this->actingAs($u)->post(route('inventory.materials.catalog',$m),[
   'catalog_category_id'=>$category->id,
  ])->assertSessionHasErrors('catalog_category_id');
  $this->actingAs($u)->get(route('inventory.materials.index'))->assertOk()->assertSee('Approved category');
 }
}