<?php

namespace Tests\Feature;

use App\Models\{Currency,FinancialAccount,FinishedGood,JournalEntry,MoneyTransaction,ProductionBatch,ProductionOrder,ProductionRecipe,TradeParty,TradeSettlementAllocation,User};
use App\Services\Accounting\{EntityAccountProvisioner,JournalPostingService};
use App\Services\{SalesCreditNoteService,SalesInvoiceService};
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesPaidRefundWorkflowTest extends TestCase
{
 use RefreshDatabase;

 public function test_previously_paid_sale_can_be_returned_without_negative_receivable():void
 {
  $this->seed(ChartOfAccountsSeeder::class);
  $user=User::factory()->create();
  foreach(['sales.create','sales.view','finance.update','inventory.update'] as $p)
   Permission::findOrCreate($p,'web');
  $user->givePermissionTo(['sales.create','sales.view','finance.update','inventory.update']);
  $this->actingAs($user);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $customer=TradeParty::create(['type'=>'customer','name'=>'Return test customer',
   'is_active'=>true,'created_by'=>$user->id]);
  $customer->currencies()->attach($currency->id);
  $customerAccounts=app(EntityAccountProvisioner::class)->provision(
   'customer',$customer->id,$customer->name,$currency->id);
  $cash=FinancialAccount::create(['type'=>'cashbox','name'=>'Return test cash',
   'currency_id'=>$currency->id,'is_active'=>true,'created_by'=>$user->id]);
  $cashAccount=app(EntityAccountProvisioner::class)->provision(
   'cashbox',$cash->id,$cash->name,$currency->id)['cash'];

  $recipe=ProductionRecipe::create(['name'=>'Synthetic refund recipe','method'=>'powder_blending',
   'package_weight_kg'=>5,'package_unit'=>'bag','status'=>'approved']);
  $order=ProductionOrder::create(['number'=>'PRD-REF-01','production_recipe_id'=>$recipe->id,
   'package_count'=>5,'status'=>'completed']);
  $good=FinishedGood::create(['sku'=>'FG-REF-01','name'=>'Returnable batch',
   'package_unit'=>'bag','package_weight_kg'=>'5.000']);
  $good->forceFill(['on_hand_packages'=>5])->save();
  $batch=ProductionBatch::create(['production_order_id'=>$order->id,'finished_good_id'=>$good->id,
   'batch_number'=>'BAT-REF-01','reported_packages'=>5]);
  $batch->forceFill(['accepted_packages'=>5,'status'=>'released','released_by'=>$user->id,'released_at'=>now()])->save();
  $good->movements()->create(['production_batch_id'=>$batch->id,'type'=>'production_receipt',
   'quantity_packages'=>5,'occurred_on'=>today()->toDateString(),'created_by'=>$user->id]);

  $invoice=app(SalesInvoiceService::class)->post([
   'submission_token'=>(string)Str::uuid(),'customer_id'=>$customer->id,
   'currency_id'=>$currency->id,'income_ledger_id'=>\App\Models\LedgerAccount::where('code','4110')->value('id'),
   'invoice_date'=>today()->toDateString(),'due_date'=>today()->addDays(14)->toDateString(),
   'lines'=>[['dispatch_token'=>(string)Str::uuid(),'production_batch_id'=>$batch->id,
    'quantity_packages'=>4,'unit_price'=>'12.5000']],
  ],$user->id);
  $this->assertSame('50.0000',$invoice->total);
  // A posted, allocated receipt of 30 leaves 20 unpaid.
  $receiptJournal=JournalEntry::create(['number'=>'REC-REF-TEST','entry_date'=>today()->toDateString(),
   'description'=>'Received partial invoice payment','currency_id'=>$currency->id,
   'status'=>'draft','created_by'=>$user->id]);
  $receiptJournal->lines()->createMany([
   ['ledger_account_id'=>$cashAccount->id,'debit'=>'30.0000','credit'=>'0'],
   ['ledger_account_id'=>$customerAccounts['receivable']->id,'debit'=>'0','credit'=>'30.0000'],
  ]);
  app(JournalPostingService::class)->post($receiptJournal,$user->id);
  $payment=MoneyTransaction::create(['submission_token'=>(string)Str::uuid(),'type'=>'receipt',
   'transaction_date'=>today()->toDateString(),'source_account_id'=>null,
   'destination_account_id'=>$cash->id,'counterpart_ledger_account_id'=>$customerAccounts['receivable']->id,
   'currency_id'=>$currency->id,'amount'=>'30.0000','reference'=>'REC-PARTIAL',
   'description'=>'Partial sales payment','journal_entry_id'=>$receiptJournal->id,'created_by'=>$user->id]);
  TradeSettlementAllocation::create(['trade_document_id'=>$invoice->trade_document_id,
   'money_transaction_id'=>$payment->id,'amount'=>'30.0000','created_by'=>$user->id]);

  $input=['submission_token'=>(string)Str::uuid(),'credit_date'=>today()->toDateString(),
   'reason'=>'All four packages returned after partial payment',
   'refund_source_account_id'=>$cash->id,'refund_reference'=>'REFUND-APPROVED-01',
   'lines'=>[['sales_invoice_line_id'=>$invoice->lines->firstOrFail()->id,
    'quantity_packages'=>4,'restock'=>false]]];
  $note=app(SalesCreditNoteService::class)->post($invoice->id,$input,$user->id);
  $same=app(SalesCreditNoteService::class)->post($invoice->id,$input,$user->id);
  $this->assertSame($note->id,$same->id);
  $this->assertSame('50.0000',$note->total);
  $this->assertSame('20.0000',$note->tradeDocumentCredit->amount);
  $this->assertSame('30.0000',$note->refund->amount);
  $this->assertSame('posted',$note->refund->transaction->journal->status);
  $this->assertDatabaseCount('sales_credit_note_refunds',1);
  $this->assertDatabaseCount('sales_return_quarantines',1);
  $this->assertDatabaseHas('sales_return_quarantines',[
   'production_batch_id'=>$batch->id,'quantity_packages'=>4,'status'=>'quarantined',
  ]);
  $this->assertDatabaseCount('trade_document_credits',1);
  $this->assertDatabaseCount('money_transactions',2);
  $this->assertSame(1,$good->fresh()->on_hand_packages);
  $quality=User::factory()->create();
  foreach(['laboratory.update','inventory.update','sales.view'] as $permission)
   Permission::findOrCreate($permission,'web');
  $quality->givePermissionTo(['laboratory.update','inventory.update','sales.view']);
  $q=\App\Models\SalesReturnQuarantine::firstOrFail();
  $this->actingAs($user)->post(route('sales.returns.quarantine.decision',$q),[
   'action'=>'release','decided_on'=>today()->toDateString(),
   'quality_reference'=>'QC-RETURN-01','decision_notes'=>'Source operator cannot approve own return release.',
  ])->assertForbidden();
  $this->actingAs($quality)->post(route('sales.returns.quarantine.decision',$q),[
   'action'=>'release','decided_on'=>today()->toDateString(),
   'quality_reference'=>'QC-RETURN-01','decision_notes'=>'Independent QC sample review passed return inspection.',
  ])->assertRedirect();
  $this->assertSame(5,$good->fresh()->on_hand_packages);
  $this->assertSame(5,app(\App\Services\FinishedGoodDispatchService::class)->availablePackages($batch->fresh()));
  $this->assertDatabaseHas('sales_return_quarantines',['id'=>$q->id,'status'=>'released']);
  $this->actingAs($quality)->post(route('sales.returns.quarantine.decision',$q),[
   'action'=>'release','decided_on'=>today()->toDateString(),
   'quality_reference'=>'QC-RETURN-01','decision_notes'=>'Second release attempt must not double count.',
  ])->assertSessionHasErrors('action');
  $this->assertSame(5,$good->fresh()->on_hand_packages);
  $this->actingAs($user)->get(route('sales.credit-notes.show',$note))
   ->assertOk()->assertSee('REFUND-APPROVED-01');
  $this->assertSame('50.0000',\App\Models\JournalLine::where('journal_entry_id',$note->journal_entry_id)->sum('credit') == 50 ? '50.0000' : 'wrong');
 }

 public function test_nonexistent_invoice_id_is_rejected_before_handling_refund_request():void
 {
  $guest=User::factory()->create();
  Permission::findOrCreate('sales.create','web');
  $guest->givePermissionTo('sales.create');
  $this->actingAs($guest)->post(route('sales.credit-notes.store',123),[
   'refund_source_account_id'=>7,
  ])->assertNotFound();
 }
}
