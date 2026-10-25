<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class GeneralLedgerTest extends TestCase {
 use RefreshDatabase;
 public function test_ledger_includes_only_posted_lines_and_keeps_currency_separate():void {
  $u=User::factory()->create();Permission::findOrCreate('administration.view','web');$u->givePermissionTo('administration.view');$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true,'is_base'=>false]);$a=LedgerAccount::create(['code'=>'1111','name'=>'Cash','type'=>'asset','is_active'=>true,'is_group'=>false]);
  foreach([[$afn,'posted',10],[$afn,'draft',999],[$usd,'posted',25]] as $i=>$v){[$currency,$status,$amount]=$v;$entry=JournalEntry::create(['number'=>'JE-'.($i+1),'entry_date'=>'2026-10-20','description'=>'Test','status'=>$status,'currency_id'=>$currency->id,'created_by'=>$u->id]);$entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>$amount,'credit'=>0]);}
  $response=$this->actingAs($u)->get(route('finance.ledger.index',['account_id'=>$a->id,'currency_id'=>$afn->id]));$response->assertOk()->assertSee('>10</span>',false)->assertDontSee('>999</span>',false)->assertDontSee('>25</span>',false);
 }
 public function test_unprivileged_user_cannot_view_ledger():void {$this->actingAs(User::factory()->create())->get(route('finance.ledger.index'))->assertForbidden();}
}
