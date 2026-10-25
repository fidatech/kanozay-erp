<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Models\TradeSettlementAllocation;
use App\Models\User;
use App\Services\Accounting\TradeSettlementService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class TradeSettlementTest extends TestCase {
 use RefreshDatabase;
 private function fixtures(string $type='receivable'):array {
  $user=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$user->givePermissionTo(['administration.view','administration.update']);$this->seed(ChartOfAccountsSeeder::class);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true]);$party=TradeParty::create(['type'=>$type==='receivable'?'customer':'supplier','name'=>'Settlement Test Party','is_active'=>true,'created_by'=>$user->id]);$party->currencies()->attach($currency->id);$party->provisionLedgerAccountsForCurrency($currency->id);
  $purpose=$type;$ledger=DB::table('entity_ledger_accounts')->where(['entity_type'=>$party->type,'entity_id'=>$party->id,'currency_id'=>$currency->id,'purpose'=>$purpose])->value('ledger_account_id');
  $journal=JournalEntry::create(['number'=>'JE-ST-1','entry_date'=>'2026-10-25','description'=>'Settlement test','currency_id'=>$currency->id,'created_by'=>$user->id,'status'=>'posted']);
  $document=TradeDocument::create(['type'=>$type,'number'=>'DOC-ST-1','trade_party_id'=>$party->id,'currency_id'=>$currency->id,'document_date'=>'2026-10-25','due_date'=>'2026-11-25','amount'=>'500.0000','description'=>'Test document','journal_entry_id'=>$journal->id,'created_by'=>$user->id]);
  $paymentJournal=JournalEntry::create(['number'=>'JE-ST-2','entry_date'=>'2026-10-25','description'=>'Payment test','currency_id'=>$currency->id,'created_by'=>$user->id,'status'=>'posted']);
  $payment=MoneyTransaction::create(['type'=>$type==='receivable'?'receipt':'payment','transaction_date'=>'2026-10-25','counterpart_ledger_account_id'=>$ledger,'currency_id'=>$currency->id,'amount'=>'300.0000','description'=>'Test payment','journal_entry_id'=>$paymentJournal->id,'created_by'=>$user->id]);
  return [$user,$document,$payment,$currency];
 }
 public function test_partial_customer_settlement_preserves_journal_count():void {[$user,$doc,$payment]=$this->fixtures();$before=JournalEntry::count();$this->actingAs($user)->post(route('finance.settlements.store'),['trade_document_id'=>$doc->id,'money_transaction_id'=>$payment->id,'amount'=>'200.50'])->assertRedirect();$this->assertDatabaseHas('trade_settlement_allocations',['trade_document_id'=>$doc->id,'amount'=>'200.5000']);$this->assertSame($before,JournalEntry::count());$this->actingAs($user)->get(route('finance.documents.index'))->assertOk()->assertSee('299.5');}
 public function test_supplier_settlement_and_overallocation_rejection():void {[$user,$doc,$payment]=$this->fixtures('payable');$service=app(TradeSettlementService::class);$service->allocate($doc->id,$payment->id,'250',$user->id);$this->assertDatabaseCount('trade_settlement_allocations',1);$this->expectException(ValidationException::class);$service->allocate($doc->id,$payment->id,'100',$user->id);}
 public function test_aging_report_and_unapplied_payment_are_visible():void {[$user,$doc,$payment]=$this->fixtures();$this->actingAs($user)->get(route('finance.aging.index',['type'=>'receivable','as_of'=>'2026-12-10']))->assertOk()->assertSee('Settlement Test Party')->assertSee('1–30 days')->assertSee('500');$this->actingAs($user)->get(route('finance.money.index'))->assertOk()->assertSee('Unapplied')->assertSee('300');}
 public function test_partial_allocation_updates_aging_and_unapplied_amount():void {[$user,$doc,$payment]=$this->fixtures();app(TradeSettlementService::class)->allocate($doc->id,$payment->id,'125.125',$user->id);$this->actingAs($user)->get(route('finance.aging.index',['as_of'=>now()->addDays(90)->toDateString()]))->assertOk()->assertSee('374.875');$this->actingAs($user)->get(route('finance.money.index'))->assertOk()->assertSee('174.875');$this->assertDatabaseCount('journal_entries',2);}
 public function test_rejects_allocation_beyond_remaining_payment():void {[$user,$doc,$payment]=$this->fixtures();$service=app(TradeSettlementService::class);$service->allocate($doc->id,$payment->id,'275',$user->id);$journal=JournalEntry::create(['number'=>'JE-ST-3','entry_date'=>'2026-10-25','description'=>'Second document','currency_id'=>$doc->currency_id,'created_by'=>$user->id,'status'=>'posted']);$second=TradeDocument::create(['type'=>'receivable','number'=>'DOC-ST-2','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$doc->currency_id,'document_date'=>'2026-10-25','due_date'=>'2026-11-25','amount'=>'100','description'=>'Second document','journal_entry_id'=>$journal->id,'created_by'=>$user->id]);$this->expectException(ValidationException::class);$service->allocate($second->id,$payment->id,'30',$user->id);}
 public function test_reconciliation_report_displays_party_balances():void {[$user,$doc,$payment]=$this->fixtures();$this->actingAs($user)->get(route('finance.reconciliation.index'))->assertOk()->assertSee('Settlement Test Party')->assertSee('Unapplied Payments')->assertSee('500')->assertSee('300');$this->actingAs(User::factory()->create())->get(route('finance.reconciliation.index'))->assertForbidden();}
 public function test_reconciliation_shows_posted_party_ledger_balance_and_difference():void {[$user,$doc,$payment]=$this->fixtures();$ledger=$payment->counterpart_ledger_account_id;\App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$ledger,'debit'=>'500','credit'=>0]);$this->actingAs($user)->get(route('finance.reconciliation.index'))->assertOk()->assertSee('Posted Ledger Balance')->assertSee('Difference')->assertSee('200')->assertSee('View ledger')->assertSee('Investigate');}
 public function test_reconciliation_breakdown_lists_only_selected_party_currency_transactions():void {[$user,$doc,$payment,$currency]=$this->fixtures();$this->actingAs($user)->get(route('finance.reconciliation.index',['party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id]))->assertOk()->assertSee('Transaction Breakdown')->assertSee('Posted Ledger Lines')->assertSee('DOC-ST-1')->assertSee('300')->assertSee('500');$this->actingAs($user)->get(route('finance.reconciliation.index',['party_id'=>999999,'currency_id'=>$currency->id]))->assertOk()->assertDontSee('Transaction Breakdown');}
 public function test_reconciliation_breakdown_includes_posted_manual_ledger_adjustment():void {[$user,$doc,$payment,$currency]=$this->fixtures();\App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'77.25','credit'=>0,'memo'=>'Manual reconciliation adjustment']);$this->actingAs($user)->get(route('finance.reconciliation.index',['party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id]))->assertOk()->assertSee('Manual reconciliation adjustment')->assertSee('77.25')->assertSee('JE-ST-1');}
 public function test_reconciliation_summary_flags_difference_and_matching_balance():void {[$user,$doc,$payment,$currency]=$this->fixtures();$params=['party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id];$this->actingAs($user)->get(route('finance.reconciliation.index',$params))->assertOk()->assertSee('No posted journal lines found');\App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'100','credit'=>0]);$this->actingAs($user)->get(route('finance.reconciliation.index',$params))->assertOk()->assertSee('Difference detected');\App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'100','credit'=>0]);$this->actingAs($user)->get(route('finance.reconciliation.index',$params))->assertOk()->assertSee('Matched: settlement exposure equals');}
 public function test_reconciliation_case_can_be_assigned_and_resolved_by_authorized_user():void {[$user,$doc,$payment,$currency]=$this->fixtures();$data=['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','assigned_to'=>$user->id,'notes'=>'Review opening journal'];$this->actingAs($user)->post(route('finance.reconciliation.cases.save'),$data)->assertRedirect();$this->assertDatabaseHas('reconciliation_cases',['trade_party_id'=>$doc->trade_party_id,'status'=>'investigating','assigned_to'=>$user->id]);$this->actingAs($user)->get(route('finance.reconciliation.index',['party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id]))->assertOk()->assertSee('Review opening journal')->assertSee('Investigation Register');$this->actingAs($user)->post(route('finance.reconciliation.cases.save'),array_merge($data,['status'=>'resolved']))->assertRedirect();$this->assertDatabaseHas('reconciliation_cases',['trade_party_id'=>$doc->trade_party_id,'status'=>'investigating']);$this->assertDatabaseCount('reconciliation_case_events',2);}
 public function test_independent_reconciliation_approval_and_automatic_reopening():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  \App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'200','credit'=>0]);
  $data=['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Verified entries'];
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),$data)->assertRedirect();
  $case=\App\Models\ReconciliationCase::firstOrFail();
  $this->actingAs($user)->post(route('finance.reconciliation.cases.approve',$case))->assertForbidden();
  $reviewer=User::factory()->create();$reviewer->givePermissionTo('administration.update');
  $this->actingAs($reviewer)->post(route('finance.reconciliation.cases.approve',$case))->assertSessionHasErrors('resolution_notes');
  $this->actingAs($reviewer)->post(route('finance.reconciliation.cases.approve',$case),['resolution_notes'=>'Reviewed posted journal and settlement source documents.'])->assertRedirect();
  $this->assertDatabaseHas('reconciliation_cases',['id'=>$case->id,'status'=>'resolved','approved_by'=>$reviewer->id]);
  $this->assertDatabaseHas('reconciliation_case_events',['reconciliation_case_id'=>$case->id,'action'=>'approved','notes'=>'Reviewed posted journal and settlement source documents.']);
  $this->actingAs($user)->get(route('finance.reconciliation.index',['party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id]))->assertOk()->assertSee('Investigation History');
  $this->actingAs($user)->post(route('finance.reconciliation.cases.reopen',$case),
    ['reopen_reason'=>'Recheck requested without any new financial posting.'])->assertStatus(422);
  $this->assertDatabaseHas('reconciliation_cases',['id'=>$case->id,'status'=>'resolved']);
  \App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'1','credit'=>0]);
  $this->actingAs($user)->get(route('finance.reconciliation.index'))->assertOk();
  $this->assertDatabaseHas('reconciliation_cases',['id'=>$case->id,'status'=>'resolved']);
  $this->assertDatabaseMissing('reconciliation_case_events',['reconciliation_case_id'=>$case->id,'action'=>'auto_reopened']);
  $this->actingAs($user)->get(route('finance.reconciliation.index'))->assertSee('Balance changed — review required');
  $this->actingAs($user)->post(route('finance.reconciliation.cases.reopen',$case),['reopen_reason'=>'short'])->assertSessionHasErrors('reopen_reason');
  $this->assertDatabaseHas('reconciliation_cases',['id'=>$case->id,'status'=>'resolved']);
  $this->actingAs($user)->post(route('finance.reconciliation.cases.reopen',$case),['reopen_reason'=>'New ledger posting requires another reconciliation review.'])->assertRedirect();
  $this->assertDatabaseHas('reconciliation_cases',['id'=>$case->id,'status'=>'investigating','approved_by'=>null]);
  $this->assertDatabaseHas('reconciliation_case_events',['reconciliation_case_id'=>$case->id,'action'=>'reopened','actor_id'=>$user->id]);
 }
 public function test_reconciliation_approval_rejects_unmapped_currency_receipts():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  \App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'200','credit'=>0]);
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Checking mapping'])->assertRedirect();
  $reviewer=User::factory()->create();$reviewer->givePermissionTo('administration.update');
  app(TradeSettlementService::class)->allocate($doc->id,$payment->id,'50',$user->id);
  $payment->update(['counterpart_ledger_account_id'=>null]);
  $this->actingAs($reviewer)->post(route('finance.reconciliation.cases.approve',\App\Models\ReconciliationCase::firstOrFail()),['resolution_notes'=>'All transactions checked carefully'])->assertStatus(422);
  $this->assertDatabaseHas('reconciliation_cases',['trade_party_id'=>$doc->trade_party_id,'status'=>'investigating']);
 }
 public function test_unallocated_unmapped_receipt_does_not_block_unrelated_party_approval():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  \App\Models\JournalLine::create(['journal_entry_id'=>$doc->journal_entry_id,'ledger_account_id'=>$payment->counterpart_ledger_account_id,'debit'=>'200','credit'=>0]);
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Reviewed'])->assertRedirect();
  $payment->update(['counterpart_ledger_account_id'=>null]);
  $reviewer=User::factory()->create();$reviewer->givePermissionTo('administration.update');
  $this->actingAs($reviewer)->post(route('finance.reconciliation.cases.approve',\App\Models\ReconciliationCase::firstOrFail()),['resolution_notes'=>'Reviewed party ledger and documents'])->assertRedirect();
  $this->assertDatabaseHas('reconciliation_cases',['trade_party_id'=>$doc->trade_party_id,'status'=>'resolved']);
 }
 public function test_reconciliation_audit_events_cannot_be_changed_through_eloquent():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Audit baseline'])->assertRedirect();
  $event=\App\Models\ReconciliationCaseEvent::firstOrFail();
  try {$event->update(['notes'=>'Altered']);$this->fail('Update should be rejected');}
  catch (\LogicException $exception) {$this->assertStringContainsString('immutable',$exception->getMessage());}
  try {$event->delete();$this->fail('Delete should be rejected');}
  catch (\LogicException $exception) {$this->assertStringContainsString('cannot be deleted',$exception->getMessage());}
  $this->assertDatabaseHas('reconciliation_case_events',['id'=>$event->id,'notes'=>'Audit baseline']);
 }
 public function test_reconciliation_audit_verifier_detects_database_tampering():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Original audit'])->assertRedirect();
  $this->artisan('reconciliation:verify-audit')->assertExitCode(0);
  \Illuminate\Support\Facades\DB::table('reconciliation_case_events')->update(['notes'=>'Tampered']);
  $this->artisan('reconciliation:verify-audit')->assertExitCode(1);
 }
 public function test_audit_anchor_detects_deleted_final_event():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Anchor baseline'])->assertRedirect();
  $path=sys_get_temp_dir().'/kanozay-audit-'.uniqid().'.json';
  try {
   $this->artisan('reconciliation:export-audit-anchor',['path'=>$path])->assertExitCode(0);
   $this->artisan('reconciliation:verify-audit',['--anchor'=>$path])->assertExitCode(0);
   \Illuminate\Support\Facades\DB::table('reconciliation_case_events')->delete();
   $this->artisan('reconciliation:verify-audit',['--anchor'=>$path])->assertExitCode(1);
  } finally {if(file_exists($path))unlink($path);}
 }
 public function test_legacy_audit_inspection_and_unsigned_append_guard():void {
  [$user,$doc,$payment,$currency]=$this->fixtures();
  $this->actingAs($user)->post(route('finance.reconciliation.cases.save'),['type'=>'receivable','trade_party_id'=>$doc->trade_party_id,'currency_id'=>$currency->id,'status'=>'investigating','notes'=>'Initial'])->assertRedirect();
  $event=\App\Models\ReconciliationCaseEvent::firstOrFail();
  \Illuminate\Support\Facades\DB::table('reconciliation_case_events')->where('id',$event->id)->update(['event_hash'=>null]);
  $this->artisan('reconciliation:inspect-legacy-audit')->assertExitCode(1);
  $this->expectException(\LogicException::class);
  \App\Models\ReconciliationCaseEvent::create(['reconciliation_case_id'=>$event->reconciliation_case_id,'actor_id'=>$user->id,'action'=>'updated','new_status'=>'investigating']);
 }
 public function test_reconciliation_flags_unmapped_payment_instead_of_hiding_it():void {[$user,$doc,$payment]=$this->fixtures();$payment->update(['counterpart_ledger_account_id'=>null]);$this->actingAs($user)->get(route('finance.reconciliation.index'))->assertOk()->assertSee('Unmapped Payment Exceptions')->assertSee('Missing party ledger mapping')->assertSee('Attention')->assertSee('#'.$payment->id);}
 public function test_rejects_mismatched_currency_and_unauthorized_access():void {[$user,$doc,$payment]=$this->fixtures();$other=Currency::create(['code'=>'USD','name'=>'Dollar','is_active'=>true]);$payment->update(['currency_id'=>$other->id]);$this->actingAs($user)->post(route('finance.settlements.store'),['trade_document_id'=>$doc->id,'money_transaction_id'=>$payment->id,'amount'=>'10'])->assertSessionHasErrors('money_transaction_id');$this->actingAs(User::factory()->create())->post(route('finance.settlements.store'),['trade_document_id'=>$doc->id,'money_transaction_id'=>$payment->id,'amount'=>'10'])->assertForbidden();$this->assertDatabaseCount('trade_settlement_allocations',0);}
}
