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
 public function test_rejects_mismatched_currency_and_unauthorized_access():void {[$user,$doc,$payment]=$this->fixtures();$other=Currency::create(['code'=>'USD','name'=>'Dollar','is_active'=>true]);$payment->update(['currency_id'=>$other->id]);$this->actingAs($user)->post(route('finance.settlements.store'),['trade_document_id'=>$doc->id,'money_transaction_id'=>$payment->id,'amount'=>'10'])->assertSessionHasErrors('money_transaction_id');$this->actingAs(User::factory()->create())->post(route('finance.settlements.store'),['trade_document_id'=>$doc->id,'money_transaction_id'=>$payment->id,'amount'=>'10'])->assertForbidden();$this->assertDatabaseCount('trade_settlement_allocations',0);}
}
