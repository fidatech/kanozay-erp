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
 public function test_account_with_journal_history_cannot_change_code_type_or_kind():void {
  $u=$this->admin();$a=LedgerAccount::create($this->payload(['code'=>'1110','name'=>'Bank','is_group'=>0]));
  $c=\App\Models\Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);
  $entry=\App\Models\JournalEntry::create(['number'=>'JE-A-01','entry_date'=>'2026-10-25','description'=>'Posted history','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'posted']);
  $entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);
  $this->actingAs($u)->put(route('admin.accounts.update',$a),$this->payload(['code'=>'1111','name'=>'Bank','is_group'=>0]))->assertSessionHasErrors('code');
  $this->actingAs($u)->put(route('admin.accounts.update',$a),$this->payload(['code'=>'1110','name'=>'Bank','type'=>'expense','is_group'=>0]))->assertSessionHasErrors('type');
  $this->actingAs($u)->put(route('admin.accounts.update',$a),$this->payload(['code'=>'1110','name'=>'Bank','is_group'=>1]))->assertSessionHasErrors('type');
  $this->actingAs($u)->put(route('admin.accounts.update',$a),$this->payload(['code'=>'1110','name'=>'Updated Bank','is_group'=>0,'is_active'=>0]))->assertRedirect();
  $this->assertDatabaseHas('ledger_accounts',['id'=>$a->id,'code'=>'1110','name'=>'Updated Bank','type'=>'asset','is_active'=>0]);
  $this->assertDatabaseHas('activity_logs',['action'=>'account.updated','subject_id'=>$a->id]);
 }

 public function test_entity_mapped_account_cannot_be_deactivated():void {
  $u=$this->admin();$account=LedgerAccount::create($this->payload(['code'=>'1150','name'=>'Receivables','is_group'=>0]));
  $currency=\App\Models\Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);
  $party=\App\Models\TradeParty::create(['type'=>'customer','name'=>'Test Customer','created_by'=>$u->id]);
  \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->insert(['entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$currency->id,'purpose'=>'receivable','ledger_account_id'=>$account->id,'created_at'=>now(),'updated_at'=>now()]);
  $this->actingAs($u)->put(route('admin.accounts.update',$account),$this->payload(['code'=>'1150','name'=>'Receivables','is_group'=>0,'is_active'=>0]))->assertSessionHasErrors('is_active');
  $this->assertTrue($account->fresh()->is_active);
 }

}
