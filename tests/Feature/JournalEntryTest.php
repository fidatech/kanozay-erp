<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class JournalEntryTest extends TestCase {
 use RefreshDatabase;
 private function setupAccounts():array {$u=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$u->givePermissionTo(['administration.view','administration.update']);$c=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);$a=LedgerAccount::create(['code'=>'1111','name'=>'Cash','type'=>'asset','is_active'=>true,'is_group'=>false]);$b=LedgerAccount::create(['code'=>'3100','name'=>'Capital','type'=>'equity','is_active'=>true,'is_group'=>false]);DocumentSequence::create(['document_type'=>'journal_entry','prefix'=>'JE-','next_number'=>1,'padding'=>5,'is_active'=>true]);return [$u,$c,$a,$b];}
 public function test_balanced_journal_can_be_posted_and_reversed():void {[$u,$c,$a,$b]=$this->setupAccounts();$entry=JournalEntry::create(['number'=>'JE-00001','entry_date'=>'2026-10-25','description'=>'Capital','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'draft']);$entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);$entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>0,'credit'=>100]);$this->actingAs($u);$service=app(JournalPostingService::class);$service->post($entry,$u->id);$this->assertSame('posted',$entry->fresh()->status);$reverse=$service->reverse($entry,$u->id);$this->assertSame('posted',$reverse->status);$this->assertSame($entry->id,$reverse->reversal_of_id);$this->expectException(ValidationException::class);$service->reverse($entry,$u->id);}
 public function test_posting_mapped_party_journal_with_existing_reconciliation_case():void {
  [$u,$c,$a,$b]=$this->setupAccounts();
  $party=\App\Models\TradeParty::create(['type'=>'customer','name'=>'Mapped Customer','created_by'=>$u->id]);
  \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->insert([
   'entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$c->id,
   'purpose'=>'receivable','ledger_account_id'=>$a->id,'created_at'=>now(),'updated_at'=>now()]);
  $case=\App\Models\ReconciliationCase::create([
   'type'=>'receivable','trade_party_id'=>$party->id,'currency_id'=>$c->id,
   'status'=>'investigating','updated_by'=>$u->id]);
  $entry=JournalEntry::create([
   'number'=>'JE-MAPPED-01','entry_date'=>'2026-10-25','description'=>'Mapped party posting',
   'currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'draft']);
  $entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);
  $entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>0,'credit'=>100]);
  app(JournalPostingService::class)->post($entry,$u->id);
  $this->assertSame('posted',$entry->fresh()->status);
  $this->assertSame('investigating',$case->fresh()->status);
 }
 public function test_unbalanced_journal_is_rejected():void {[$u,$c,$a,$b]=$this->setupAccounts();$entry=JournalEntry::create(['number'=>'JE-00001','entry_date'=>'2026-10-25','description'=>'Test','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'draft']);$entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);$entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>0,'credit'=>99]);$this->actingAs($u);$this->expectException(ValidationException::class);app(JournalPostingService::class)->post($entry,$u->id);}
 public function test_journal_lines_show_party_names_without_account_codes():void {[$u,$c,$a,$b]=$this->setupAccounts();$party=\App\Models\TradeParty::create(['type'=>'customer','name'=>'Kabul Wholesale Customer','created_by'=>$u->id]);\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->insert(['entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$c->id,'purpose'=>'receivable','ledger_account_id'=>$a->id,'created_at'=>now(),'updated_at'=>now()]);$entry=JournalEntry::create(['number'=>'JE-PARTY-01','entry_date'=>'2026-10-25','description'=>'Party journal','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'posted']);$entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);$entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>0,'credit'=>100]);$this->actingAs($u)->get(route('finance.journals.index'))->assertOk()->assertSee('Kabul Wholesale Customer')->assertSee('>100</span>',false);}
 public function test_journal_creation_requires_permission():void {[$u,$c,$a,$b]=$this->setupAccounts();$other=User::factory()->create();$this->actingAs($other)->get(route('finance.journals.index'))->assertForbidden();$this->actingAs($u)->get(route('finance.journals.index'))->assertOk();}
}
