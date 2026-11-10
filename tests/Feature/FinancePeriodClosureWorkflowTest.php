<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinancePeriodClosureWorkflowTest extends TestCase {
 use RefreshDatabase;
 private function accounts():array {
  foreach(['finance.view','finance.update'] as $p)Permission::findOrCreate($p,'web');
  $closer=User::factory()->create();$closer->givePermissionTo(['finance.view','finance.update']);
  $reopener=User::factory()->create();$reopener->givePermissionTo(['finance.view','finance.update']);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $debit=LedgerAccount::create(['code'=>'TEST-FP-ASSET','name'=>'Synthetic cash',
   'type'=>'asset','is_active'=>true,'is_group'=>false]);
  $credit=LedgerAccount::create(['code'=>'TEST-FP-LIAB','name'=>'Synthetic capital',
   'type'=>'liability','is_active'=>true,'is_group'=>false]);
  return [$closer,$reopener,$currency,$debit,$credit];
 }
 private function journal(User $u,Currency $c,LedgerAccount $a,LedgerAccount $b,string $month):JournalEntry {
  $j=JournalEntry::create(['number'=>'FP-'.uniqid(),'entry_date'=>$month,
   'description'=>'Synthetic period check','currency_id'=>$c->id,'status'=>'draft','created_by'=>$u->id]);
  $j->lines()->createMany([
   ['ledger_account_id'=>$a->id,'debit'=>'125.0000','credit'=>'0'],
   ['ledger_account_id'=>$b->id,'debit'=>'0','credit'=>'125.0000'],
  ]);
  return $j;
 }
 public function test_posting_closed_month_is_blocked_and_independent_reopen_restores_it():void {
  [$closer,$reviewer,$currency,$debit,$credit]=$this->accounts();
  $date=today()->subMonthNoOverflow()->startOfMonth()->toDateString();
  $j=$this->journal($closer,$currency,$debit,$credit,$date);
  app(JournalPostingService::class)->post($j,$closer->id);
  $this->actingAs($closer)->post(route('finance.periods.close'),[
   'period_start'=>$date,'confirm_source_reconciliation'=>'1','authorization_reference'=>'BOARD-FP-100',
   'closing_note'=>'Approved and reviewed accounting closure for synthetic testing',
  ])->assertRedirect();
  $this->assertDatabaseHas('finance_period_closures',['period_start'=>$date,'status'=>'closed','journal_count'=>1]);
  $next=$this->journal($closer,$currency,$debit,$credit,$date);
  $this->actingAs($closer)->patch(route('finance.journals.post',$next))
   ->assertSessionHasErrors('entry_date');
  $closedId=DB::table('finance_period_closures')->value('id');
  $this->actingAs($closer)->post(route('finance.periods.reopen',$closedId),[
   'reopening_reference'=>'BOARD-FP-REOPEN','reopening_note'=>'Correction approved by independent finance reviewer',
  ])->assertSessionHasErrors('period');
  $this->actingAs($reviewer)->post(route('finance.periods.reopen',$closedId),[
   'reopening_reference'=>'BOARD-FP-REOPEN','reopening_note'=>'Correction approved by independent finance reviewer',
  ])->assertRedirect();
  $this->actingAs($closer)->patch(route('finance.journals.post',$next))->assertRedirect();
  $this->assertDatabaseHas('journal_entries',['id'=>$next->id,'status'=>'posted']);
  $this->assertDatabaseCount('finance_period_closure_events',2);
  $this->actingAs($reviewer)->get(route('finance.periods.index'))->assertOk()
   ->assertSee('BOARD-FP-REOPEN');
 }
 public function test_open_draft_journal_prevents_month_closure():void {
  [$closer,$reviewer,$currency,$a,$b]=$this->accounts();
  $date=today()->subMonthNoOverflow()->startOfMonth()->toDateString();
  $this->journal($closer,$currency,$a,$b,$date);
  $this->actingAs($closer)->post(route('finance.periods.close'),[
   'period_start'=>$date,'confirm_source_reconciliation'=>'1','authorization_reference'=>'BOARD-FP-200',
   'closing_note'=>'Attempting to close while outstanding drafts still exist',
  ])->assertSessionHasErrors('period_start');
  $this->assertDatabaseCount('finance_period_closures',0);
 }
}