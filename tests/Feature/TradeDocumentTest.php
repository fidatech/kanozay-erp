<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\TradeParty;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class TradeDocumentTest extends TestCase {
 use RefreshDatabase;
 private function fixtures(string $type='customer'):array {$user=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$user->givePermissionTo(['administration.view','administration.update']);$this->seed(ChartOfAccountsSeeder::class);$currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true]);$party=TradeParty::create(['type'=>$type,'name'=>'Test Party','is_active'=>true,'created_by'=>$user->id]);$party->currencies()->attach($currency->id);$party->provisionLedgerAccountsForCurrency($currency->id);$ledger=LedgerAccount::where('is_group',false)->where('is_active',true)->whereNotIn('id',DB::table('entity_ledger_accounts')->pluck('ledger_account_id'))->firstOrFail();return [$user,$party,$currency,$ledger];}
 public function test_receivable_posts_balanced_journal():void {[$user,$party,$currency,$ledger]=$this->fixtures();$this->actingAs($user)->post(route('finance.documents.store'),['type'=>'receivable','trade_party_id'=>$party->id,'currency_id'=>$currency->id,'document_date'=>'2026-10-25','due_date'=>'2026-11-25','amount'=>'500.00','counterpart_ledger_account_id'=>$ledger->id,'description'=>'Approved opening balance'])->assertRedirect();$this->assertDatabaseHas('trade_documents',['type'=>'receivable','amount'=>'500.0000']);$this->assertDatabaseHas('journal_entries',['status'=>'posted']);$this->assertDatabaseCount('journal_lines',2);}
 public function test_supplier_payable_posts_balanced_journal():void {[$user,$party,$currency,$ledger]=$this->fixtures('supplier');$this->actingAs($user)->post(route('finance.documents.store'),['type'=>'payable','trade_party_id'=>$party->id,'currency_id'=>$currency->id,'document_date'=>'2026-10-25','due_date'=>'2026-11-25','amount'=>'125.00','counterpart_ledger_account_id'=>$ledger->id,'description'=>'Opening supplier balance'])->assertRedirect();$this->assertDatabaseHas('trade_documents',['type'=>'payable','amount'=>'125.0000']);}
 public function test_rejects_wrong_party_type():void {[$user,$party,$currency,$ledger]=$this->fixtures();$this->actingAs($user)->post(route('finance.documents.store'),['type'=>'payable','trade_party_id'=>$party->id,'currency_id'=>$currency->id,'document_date'=>'2026-10-25','due_date'=>'2026-11-25','amount'=>'125.00','counterpart_ledger_account_id'=>$ledger->id,'description'=>'Invalid'])->assertSessionHasErrors('trade_party_id');$this->assertDatabaseCount('trade_documents',0);}
}
