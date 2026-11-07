<?php
namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ManufacturingOperationsTest extends TestCase {
 use RefreshDatabase;
 private function records():array {
  foreach(['manufacturing.view','manufacturing.create','manufacturing.update'] as $p)Permission::findOrCreate($p,'web');
  $u=User::factory()->create();$u->givePermissionTo(['manufacturing.view','manufacturing.create','manufacturing.update']);
  $recipe=ProductionRecipe::create(['name'=>'Synthetic','method'=>'powder_blending',
    'package_weight_kg'=>5,'package_unit'=>'bag','status'=>'approved']);
  $a=ProductionOrder::create(['number'=>'PRD-E-1','production_recipe_id'=>$recipe->id,'package_count'=>10,'status'=>'planned']);
  $b=ProductionOrder::create(['number'=>'PRD-E-2','production_recipe_id'=>$recipe->id,'package_count'=>10,'status'=>'planned']);
  return [$u,$a,$b];
 }
 public function test_equipment_schedules_reject_overlaps_and_record_downtime():void {
  [$user,$a,$b]=$this->records();
  $this->actingAs($user)->post(route('manufacturing.equipment.store'),['code'=>'MIXER','name'=>'Test mixer'])->assertRedirect();
  $id=DB::table('manufacturing_equipment')->value('id');
  $start=now()->addDay()->startOfHour()->format('Y-m-d H:i:s');
  $end=now()->addDay()->startOfHour()->addHours(2)->format('Y-m-d H:i:s');
  $this->actingAs($user)->post(route('manufacturing.schedules.store'),[
    'production_order_id'=>$a->id,'manufacturing_equipment_id'=>$id,
    'starts_at'=>$start,'ends_at'=>$end,
  ])->assertRedirect();
  $this->actingAs($user)->post(route('manufacturing.schedules.store'),[
    'production_order_id'=>$b->id,'manufacturing_equipment_id'=>$id,
    'starts_at'=>$start,'ends_at'=>$end,
  ])->assertSessionHasErrors('starts_at');
  $this->assertDatabaseCount('manufacturing_schedules',1);
  $down=['submission_token'=>(string)Str::uuid(),'manufacturing_equipment_id'=>$id,
    'started_at'=>now()->subMinutes(35)->format('Y-m-d H:i:s'),
    'ended_at'=>now()->subMinutes(5)->format('Y-m-d H:i:s'),
    'cause'=>'mechanical','description'=>'Synthetic equipment stoppage for review'];
  $this->actingAs($user)->post(route('manufacturing.downtime.store'),$down)->assertRedirect();
  $this->assertDatabaseHas('manufacturing_downtime_entries',['duration_minutes'=>30]);
  $this->actingAs($user)->post(route('manufacturing.downtime.store'),$down)->assertRedirect();
  $this->assertDatabaseCount('manufacturing_downtime_entries',1);
  $this->actingAs($user)->get(route('workspace.page',['manufacturing','downtime']))
    ->assertOk()->assertSee('Synthetic equipment stoppage');
 }
 public function test_operational_module_requires_manufacturing_scope():void {
  $stranger=User::factory()->create();
  $this->actingAs($stranger)->get(route('workspace.page',['manufacturing','scheduling']))->assertForbidden();
  $this->actingAs($stranger)->post(route('manufacturing.equipment.store'),[])->assertForbidden();
 }
}
