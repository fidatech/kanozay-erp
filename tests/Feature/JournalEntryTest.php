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
 public function test_posting_balances_fractional_amounts_without_float_conversion():void {
  [$u,$c,$a,$b]=$this->setupAccounts();
  $entry=JournalEntry::create(['number'=>'JE-DECIMAL-01','entry_date'=>'2026-10-25','description'=>'Fractional journal','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'draft']);
  $entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>'0.1001','credit'=>'0']);
  $entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>'0.2002','credit'=>'0']);
  $entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'0.3003']);
  app(JournalPostingService::class)->post($entry,$u->id);
  $this->assertSame('posted',$entry->fresh()->status);
 }
 public function test_journal_input_rejects_more_than_four_decimal_places():void {
  [$u,$c,$a,$b]=$this->setupAccounts();
  $this->actingAs($u)->post(route('finance.journals.store'),[
   'entry_date'=>'2026-10-25','description'=>'Precision validation','currency_id'=>$c->id,
   'lines'=>[
    ['ledger_account_id'=>$a->id,'debit'=>'0.00001','credit'=>'0'],
    ['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'0.00001'],
   ],
  ])->assertSessionHasErrors('lines.0.debit');
  $this->assertDatabaseCount('journal_entries',0);
 }
 public function test_journal_lines_show_party_names_without_account_codes():void {[$u,$c,$a,$b]=$this->setupAccounts();$party=\App\Models\TradeParty::create(['type'=>'customer','name'=>'Kabul Wholesale Customer','created_by'=>$u->id]);\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->insert(['entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$c->id,'purpose'=>'receivable','ledger_account_id'=>$a->id,'created_at'=>now(),'updated_at'=>now()]);$entry=JournalEntry::create(['number'=>'JE-PARTY-01','entry_date'=>'2026-10-25','description'=>'Party journal','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'posted']);$entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>100,'credit'=>0]);$entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>0,'credit'=>100]);$this->actingAs($u)->get(route('finance.journals.index',['advanced'=>1]))->assertOk()->assertSee('Kabul Wholesale Customer')->assertSee('>100</span>',false);}
 public function test_journal_creation_requires_permission():void {[$u,$c,$a,$b]=$this->setupAccounts();$other=User::factory()->create();$this->actingAs($other)->get(route('finance.journals.index'))->assertForbidden();$this->actingAs($u)->get(route('finance.journals.index'))->assertOk();}
 public function test_draft_validation_rejects_unbalanced_and_mixed_line_values():void {
  [$u,$c,$a,$b]=$this->setupAccounts();
  $payload=['entry_date'=>'2026-10-25','description'=>'Validation UAT','currency_id'=>$c->id,'lines'=>[
   ['ledger_account_id'=>$a->id,'debit'=>'100.0000','credit'=>'0'],
   ['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'99.9999'],
  ]];
  $this->actingAs($u)->post(route('finance.journals.store'),$payload)->assertSessionHasErrors('lines');
  $payload['lines'][1]=['ledger_account_id'=>$b->id,'debit'=>'1','credit'=>'1'];
  $this->actingAs($u)->post(route('finance.journals.store'),$payload)->assertSessionHasErrors('lines.1.debit');
  $payload['lines'][1]=['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'100'];
  $this->actingAs($u)->post(route('finance.journals.store'),$payload)->assertRedirect();
  $this->assertDatabaseCount('journal_entries',1);
  $this->assertDatabaseHas('activity_logs',['action'=>'journal.created']);
  $this->assertDatabaseHas('journal_entries',['status'=>'draft']);
 }
 public function test_reversal_journal_cannot_itself_be_reversed():void {
  [$u,$c,$a,$b]=$this->setupAccounts();
  $entry=JournalEntry::create(['number'=>'JE-REVERSE-01','entry_date'=>'2026-10-25','description'=>'Test reversal','currency_id'=>$c->id,'created_by'=>$u->id,'status'=>'draft']);
  $entry->lines()->create(['ledger_account_id'=>$a->id,'debit'=>'100','credit'=>'0']);
  $entry->lines()->create(['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'100']);
  $this->actingAs($u);$service=app(JournalPostingService::class);$service->post($entry,$u->id);
  $reversal=$service->reverse($entry,$u->id);
  $this->expectException(ValidationException::class);
  $service->reverse($reversal,$u->id);
 }

 public function test_finance_editor_can_view_and_create_journal_without_administration_permission():void {
  [$u,$c,$a,$b]=$this->setupAccounts();$finance=User::factory()->create();$finance->givePermissionTo(Permission::findOrCreate('finance.update','web'));
  $this->actingAs($finance)->get(route('finance.journals.index',['advanced'=>1]))->assertOk()->assertSee('journal-balance-preview')->assertSee('Save Draft');
  $this->actingAs($finance)->post(route('finance.journals.store'),[
   'entry_date'=>'2026-10-25','description'=>'Finance journal','currency_id'=>$c->id,'lines'=>[
    ['ledger_account_id'=>$a->id,'debit'=>'100.0000','credit'=>'0'],
    ['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'100.0000'],
   ],
  ])->assertRedirect();
  $this->assertDatabaseHas('journal_entries',['description'=>'Finance journal','status'=>'draft']);
 }

}
