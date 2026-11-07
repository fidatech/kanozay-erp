<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\GoodsReceipt;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturnRecord;
use App\Models\SupplierDebitNote;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\Accounting\JournalPostingService;
use App\Services\SupplierDebitNoteService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierDebitNoteTest extends TestCase {
 use RefreshDatabase;
 private function records():array {
  $this->seed(ChartOfAccountsSeeder::class);
  foreach(['finance.view','finance.update','purchases.view'] as $p)Permission::findOrCreate($p,'web');
  $user=User::factory()->create();$user->givePermissionTo(['finance.view','finance.update','purchases.view']);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true,'is_active'=>true]);
  $party=TradeParty::create(['type'=>'supplier','name'=>'Test supplier','is_active'=>true,'created_by'=>$user->id]);
  $party->currencies()->attach($currency->id);
  $accounts=app(EntityAccountProvisioner::class)->provision('supplier',$party->id,$party->name,$currency->id);
  $expense=LedgerAccount::where('code','5110')->firstOrFail();
  $journal=JournalEntry::create(['number'=>'MAN-AP-1','entry_date'=>today()->subDays(9)->toDateString(),
   'description'=>'Test supplier bill','status'=>'draft','currency_id'=>$currency->id,'created_by'=>$user->id]);
  $journal->lines()->createMany([
   ['ledger_account_id'=>$expense->id,'debit'=>'100','credit'=>'0'],
   ['ledger_account_id'=>$accounts['payable']->id,'debit'=>'0','credit'=>'100'],
  ]);
  app(JournalPostingService::class)->post($journal,$user->id);
  $doc=TradeDocument::create(['type'=>'payable','number'=>'AP-RET-SYN','trade_party_id'=>$party->id,
   'currency_id'=>$currency->id,'document_date'=>today()->subDays(9)->toDateString(),
   'due_date'=>today()->addWeek()->toDateString(),'amount'=>'100',
   'description'=>'Test supplier payable','journal_entry_id'=>$journal->id,'created_by'=>$user->id]);
  $raw=InventoryMaterial::create(['sku'=>'TEST-RETURN-MAT','name'=>'Synthetic ingredient','unit'=>'kg']);
  $po=PurchaseOrder::create(['number'=>'PO-SYN-RETURN','supplier_id'=>$party->id,
   'currency_id'=>$currency->id,'source'=>'local','order_date'=>today()->subDays(9)->toDateString(),
   'status'=>'confirmed','created_by'=>$user->id]);
  $item=$po->items()->create(['description'=>'Ingredient','quantity'=>'4','unit_price'=>'20','inventory_material_id'=>$raw->id]);
  $receipt=GoodsReceipt::create(['number'=>'GRN-SYN-1','purchase_order_id'=>$po->id,
   'receipt_token'=>(string)Str::uuid(),'received_on'=>today()->subDays(7)->toDateString(),
   'status'=>'posted','created_by'=>$user->id]);
  $line=$receipt->lines()->create([
   'purchase_order_item_id'=>$item->id,'inventory_material_id'=>$raw->id,
   'quantity'=>'4','unit'=>'kg','unit_price_snapshot'=>'20','line_subtotal_snapshot'=>'80',
  ]);
  $return=PurchaseReturnRecord::create([
   'number'=>'PRN-SYN-1','submission_token'=>(string)Str::uuid(),
   'goods_receipt_id'=>$receipt->id,'purchase_order_id'=>$po->id,'currency_id'=>$currency->id,
   'returned_on'=>today()->subDays(2)->toDateString(),
   'status'=>'posted_pending_credit','financial_status'=>'supplier_credit_pending',
   'reason'=>'Supplier returned goods after physical inspection','created_by'=>$user->id,
  ]);
  $return->lines()->create([
   'goods_receipt_line_id'=>$line->id,'inventory_material_id'=>$raw->id,
   'quantity'=>'2','unit_price_snapshot'=>'20','supplier_credit_value_snapshot'=>'40',
  ]);
  return [$user,$return,$doc,$expense];
 }
 public function test_posted_supplier_credit_is_balanced_and_reduces_outstanding_payable_once():void {
  [$u,$r,$doc,$offset]=$this->records();
  $data=['submission_token'=>(string)Str::uuid(),'trade_document_id'=>$doc->id,
   'credit_offset_ledger_id'=>$offset->id,'credit_date'=>today()->toDateString(),
   'amount'=>'40','supplier_credit_reference'=>'CONFIRM-SYN-123',
   'review_notes'=>'Approved against returned goods and source receipt'];
  $note=app(SupplierDebitNoteService::class)->post($r,$data,$u->id);
  $this->assertSame('40.0000',$note->amount);
  $this->assertSame('posted',$note->journal->status);
  $this->assertDatabaseHas('trade_document_credits',['trade_document_id'=>$doc->id,
   'supplier_debit_note_id'=>$note->id,'amount'=>'40.0000']);
  $this->assertSame('supplier_credit_posted',$r->fresh()->financial_status);
  $same=app(SupplierDebitNoteService::class)->post($r,$data,$u->id);
  $this->assertSame($note->id,$same->id);
  $this->assertDatabaseCount('journal_entries',2);
  $this->actingAs($u)->get(route('purchases.supplier-debit-notes.index'))->assertOk()
    ->assertSee('CONFIRM-SYN-123');
 }
 public function test_supplier_credit_cannot_exceed_original_return_valuation():void {
  [$u,$r,$doc,$offset]=$this->records();
  $data=['submission_token'=>(string)Str::uuid(),'trade_document_id'=>$doc->id,
   'credit_offset_ledger_id'=>$offset->id,'credit_date'=>today()->toDateString(),
   'amount'=>'80','supplier_credit_reference'=>'CONFIRM-SYN-123',
   'review_notes'=>'Proposed excessive supplier credit must be rejected'];
  try{
   app(SupplierDebitNoteService::class)->post($r,$data,$u->id);
   $this->fail('Supplier credit exceeds source valuation.');
  }catch(ValidationException $e){$this->assertArrayHasKey('amount',$e->errors());}
  $this->assertDatabaseCount('supplier_debit_notes',0);
 }
}
