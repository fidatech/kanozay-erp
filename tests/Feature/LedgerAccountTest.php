<?php
namespace Tests\Feature;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class LedgerAccountTest extends TestCase {
 use RefreshDatabase;
 private function admin():User {$u=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$u->givePermissionTo(['administration.view','administration.update']);return $u;}
 private function payload(array $overrides=[]):array{return array_merge(['code'=>'1000','name'=>'Assets','type'=>'asset','parent_id'=>null,'is_group'=>1,'is_active'=>1,'description'=>'Balance sheet assets'],$overrides);}
 public function test_permissions_are_enforced():void {$u=User::factory()->create();$this->actingAs($u)->get(route('admin.accounts.index'))->assertForbidden();$this->actingAs($u)->post(route('admin.accounts.store'),$this->payload())->assertForbidden();}
 public function test_accounts_can_be_created_and_listed():void {$u=$this->admin();$this->actingAs($u)->post(route('admin.accounts.store'),$this->payload())->assertRedirect();$parent=LedgerAccount::firstOrFail();$this->actingAs($u)->post(route('admin.accounts.store'),$this->payload(['code'=>'1100','name'=>'Cash','parent_id'=>$parent->id,'is_group'=>0]))->assertRedirect();$this->assertDatabaseCount('ledger_accounts',2);$this->actingAs($u)->get(route('admin.accounts.index'))->assertOk()->assertSee('Cash');}
 public function test_parent_must_match_type_and_cycles_are_rejected():void {$u=$this->admin();$parent=LedgerAccount::create($this->payload());$child=LedgerAccount::create($this->payload(['code'=>'1100','name'=>'Cash Group','parent_id'=>$parent->id]));$this->actingAs($u)->post(route('admin.accounts.store'),$this->payload(['code'=>'2000','name'=>'Payables','type'=>'liability','parent_id'=>$parent->id]))->assertSessionHasErrors('parent_id');$this->actingAs($u)->put(route('admin.accounts.update',$parent),$this->payload(['parent_id'=>$child->id]))->assertSessionHasErrors('parent_id');}
}
