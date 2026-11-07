<?php

namespace Tests\Feature;

use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LaboratoryQualityWorkflowTest extends TestCase
{
 use RefreshDatabase;
 private function fixture():array
 {
  foreach(['laboratory.view','laboratory.create','laboratory.update'] as $p)Permission::findOrCreate($p,'web');
  $technician=User::factory()->create();
  $technician->givePermissionTo(['laboratory.view','laboratory.create']);
  $reviewer=User::factory()->create();
  $reviewer->givePermissionTo(['laboratory.view','laboratory.update']);
  $recipe=ProductionRecipe::create(['name'=>'QC synthetic','method'=>'powder_blending',
    'package_weight_kg'=>5,'package_unit'=>'bag','status'=>'approved']);
  $order=ProductionOrder::create(['number'=>'PRD-QC-SYNTH','production_recipe_id'=>$recipe->id,'package_count'=>10,'status'=>'pending_qc']);
  $good=FinishedGood::create(['sku'=>'FG-QC','name'=>'Sample bags','package_unit'=>'bag','package_weight_kg'=>'5.000']);
  $batch=ProductionBatch::create(['production_order_id'=>$order->id,'finished_good_id'=>$good->id,
    'batch_number'=>'B-QC-1','reported_packages'=>10]);
  return [$technician,$reviewer,$good,$batch];
 }
 public function test_sample_requires_approved_specification_and_independent_review():void
 {
  [$tech,$reviewer,$good,$batch]=$this->fixture();
  $this->actingAs($reviewer)->post(route('laboratory.specifications.store'),[
    'subject_type'=>'finished_good','subject_id'=>$good->id,
    'test_name'=>'Moisture','kind'=>'numeric','minimum'=>'0','maximum'=>'3','unit'=>'%',
  ])->assertRedirect();
  $specId=DB::table('quality_specifications')->value('id');
  $this->actingAs($tech)->post(route('laboratory.samples.store'),[
    'submission_token'=>(string)Str::uuid(),'source_type'=>'finished_batch',
    'production_batch_id'=>$batch->id,'collected_on'=>today()->toDateString(),
  ])->assertRedirect();
  $sampleId=DB::table('quality_samples')->value('id');
  $this->actingAs($tech)->post(route('laboratory.samples.result',$sampleId),[
    'quality_specification_id'=>$specId,'observed_value'=>'2.5',
  ])->assertRedirect();
  $this->actingAs($tech)->post(route('laboratory.samples.result',$sampleId),[
    'quality_specification_id'=>$specId,'observed_value'=>'2.5',
  ])->assertSessionHasErrors('quality_specification_id');
  $this->actingAs($reviewer)->post(route('laboratory.samples.review',$sampleId),[
    'decision'=>'approved','review_notes'=>'Results verified against approved limits',
  ])->assertRedirect();
  $this->assertDatabaseHas('quality_samples',['id'=>$sampleId,'status'=>'approved']);
  $this->actingAs($tech)->get(route('laboratory.samples.certificate',$sampleId))
    ->assertOk()->assertSee('QUALITY CONTROL CERTIFICATE');
 }
 public function test_unprivileged_user_cannot_read_or_modify_quality_records():void
 {
  [$tech,$reviewer,$good,$batch]=$this->fixture();
  $stranger=User::factory()->create();
  $this->actingAs($stranger)->get(route('workspace.page',['laboratory','samples']))->assertForbidden();
  $this->actingAs($stranger)->post(route('laboratory.samples.store'),[])->assertForbidden();
 }
}
