<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Shareholder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShareholderGovernanceTest extends TestCase {
 use RefreshDatabase;
 private function users():array {
  foreach(['shareholders.view','shareholders.update'] as $p)Permission::findOrCreate($p,'web');
  $a=User::factory()->create();$a->givePermissionTo(['shareholders.view','shareholders.update']);
  $b=User::factory()->create();$b->givePermissionTo(['shareholders.view','shareholders.update']);
  $s=Shareholder::create(['name'=>'Synthetic shareholder','created_by'=>$a->id]);
  return [$a,$b,$s];
 }
 public function test_cap_table_rejects_overallocation_and_resolutions_require_separate_reviewer():void {
  [$a,$b,$s]=$this->users();
  $another=Shareholder::create(['name'=>'Second synthetic shareholder','created_by'=>$a->id]);
  $data=['shareholder_id'=>$s->id,'share_class'=>'Ordinary','percentage'=>'60',
   'effective_on'=>today()->toDateString(),'approval_reference'=>'BOARD-APPROVED-1'];
  $this->actingAs($a)->post(route('shareholders.governance.ownership'),$data)->assertRedirect();
  $data['shareholder_id']=$another->id;$data['percentage']='50';
  $this->actingAs($a)->post(route('shareholders.governance.ownership'),$data)
    ->assertSessionHasErrors('percentage');
  $this->assertDatabaseCount('shareholder_ownership_records',1);
  $data=['number'=>'RES-SYN-1','title'=>'Recorded governance agreement','purpose'=>'governance',
    'resolution_on'=>today()->toDateString(),'document_reference'=>'SIGNED-BOARD-REF'];
  $this->actingAs($a)->post(route('shareholders.governance.resolution'),$data)->assertRedirect();
  $id=DB::table('shareholder_resolutions')->value('id');
  $this->actingAs($a)->post(route('shareholders.governance.decide',$id),['decision'=>'approved'])
    ->assertSessionHasErrors('decision');
  $this->actingAs($b)->post(route('shareholders.governance.decide',$id),['decision'=>'approved'])->assertRedirect();
  $this->assertDatabaseHas('shareholder_resolutions',['id'=>$id,'status'=>'approved']);
  $this->actingAs($a)->get(route('workspace.page',['shareholders','ownership']))
    ->assertOk()->assertSee('BOARD-APPROVED-1');
 }
}
