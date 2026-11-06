<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\TradeParty;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class TradePartyTest extends TestCase {
 use RefreshDatabase;
 private function makeFixtures():array{$user=User::factory()->create();Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$user->givePermissionTo(['administration.view','administration.update']);$this->seed(ChartOfAccountsSeeder::class);$afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true]);$usd=Currency::create(['code'=>'USD','name'=>'Dollar','is_active'=>true]);return [$user,$afn,$usd];}
 public function test_supplier_creates_payable_and_advance_accounts():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'supplier','name'=>'Test Supplier','currency_ids'=>[$afn->id]])->assertRedirect();$party=TradeParty::firstOrFail();$this->assertSame('supplier',$party->type);$this->assertDatabaseCount('entity_ledger_accounts',2);$this->assertDatabaseHas('entity_ledger_accounts',['entity_type'=>'supplier','entity_id'=>$party->id,'purpose'=>'payable']);}
 public function test_customer_can_enable_second_currency():void {[$user,$afn,$usd]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Test Customer','currency_ids'=>[$afn->id]])->assertRedirect();$party=TradeParty::firstOrFail();$this->actingAs($user)->post(route('finance.parties.currencies.store',$party),['currency_id'=>$usd->id])->assertRedirect();$this->assertDatabaseCount('entity_ledger_accounts',4);$this->assertCount(2,$party->fresh()->currencies);}
 public function test_customer_and_supplier_creation_forms_use_shared_modal_system():void {
  [$user]=$this->makeFixtures();
  foreach(['finance.customers.index'=>'customer','finance.suppliers.index'=>'supplier'] as $route=>$type){
   $this->actingAs($user)->get(route($route))->assertOk()
    ->assertSee('data-new-entry-form="1"',false)
    ->assertSee('Create '.ucfirst($type))
    ->assertSee('setupCreationDialogs()',false)
    ->assertSee('k-create-trigger',false)
    ->assertSee('k-create-ready',false);
  }
 }
 public function test_customer_register_renders_without_blade_parse_error():void {[$user]=$this->makeFixtures();$this->actingAs($user)->get(route('finance.customers.index'))->assertOk()->assertSee('Customer Register')->assertSee('Manage customer details')->assertSee('name="type" value="customer"',false);}
 public function test_supplier_register_renders_without_blade_parse_error():void {[$user]=$this->makeFixtures();$this->actingAs($user)->get(route('finance.suppliers.index'))->assertOk()->assertSee('Supplier Register')->assertSee('Manage supplier details')->assertSee('name="type" value="supplier"',false);}
 public function test_customer_and_supplier_pages_ignore_opposite_type_query():void {[$user]=$this->makeFixtures();$this->actingAs($user)->get(route('finance.customers.index',['type'=>'supplier']))->assertOk()->assertSee('Customer Register')->assertSee('name="type" value="customer"',false);$this->actingAs($user)->get(route('finance.suppliers.index',['type'=>'customer']))->assertOk()->assertSee('Supplier Register')->assertSee('name="type" value="supplier"',false);}
 public function test_customer_search_filters_name_and_keeps_suppliers_out():void {[$user,$afn]=$this->makeFixtures();TradeParty::create(['type'=>'customer','name'=>'Alpha Customer','is_active'=>true,'created_by'=>$user->id]);TradeParty::create(['type'=>'customer','name'=>'Beta Customer','is_active'=>true,'created_by'=>$user->id]);TradeParty::create(['type'=>'supplier','name'=>'Alpha Supplier','is_active'=>true,'created_by'=>$user->id]);$this->actingAs($user)->get(route('finance.customers.index',['search'=>'Alpha']))->assertOk()->assertSee('Alpha Customer')->assertDontSee('Beta Customer')->assertDontSee('Alpha Supplier');}
 public function test_supplier_search_filters_by_phone():void {[$user]=$this->makeFixtures();TradeParty::create(['type'=>'supplier','name'=>'Supplier One','phone'=>'0700123456','is_active'=>true,'created_by'=>$user->id]);TradeParty::create(['type'=>'supplier','name'=>'Supplier Two','phone'=>'0799999999','is_active'=>true,'created_by'=>$user->id]);$this->actingAs($user)->get(route('finance.suppliers.index',['search'=>'0700123456']))->assertOk()->assertSee('Supplier One')->assertDontSee('Supplier Two');}
 public function test_customer_detail_shows_profile_and_rejects_supplier_route():void {[$user]=$this->makeFixtures();$party=TradeParty::create(['type'=>'customer','name'=>'Detail Customer','phone'=>'0700000011','is_active'=>true,'created_by'=>$user->id]);$this->actingAs($user)->get(route('finance.customers.show',$party))->assertOk()->assertSee('Detail Customer')->assertSee('0700000011')->assertSee('Recent Receivables');$this->actingAs($user)->get(route('finance.suppliers.show',$party))->assertNotFound();}
 public function test_supplier_detail_shows_profile_and_rejects_customer_route():void {[$user]=$this->makeFixtures();$party=TradeParty::create(['type'=>'supplier','name'=>'Detail Supplier','is_active'=>true,'created_by'=>$user->id]);$this->actingAs($user)->get(route('finance.suppliers.show',$party))->assertOk()->assertSee('Detail Supplier')->assertSee('Recent Payables');$this->actingAs($user)->get(route('finance.customers.show',$party))->assertNotFound();}
 public function test_customer_profile_edit_records_audit_and_preserves_financial_fields():void {[$user,$afn]=$this->makeFixtures();$party=TradeParty::create(['type'=>'customer','name'=>'Old Customer','is_active'=>true,'created_by'=>$user->id]);$party->currencies()->attach($afn->id);$this->actingAs($user)->patch(route('finance.customers.update',$party),['name'=>'Updated Customer','code'=>'C-01','phone'=>'0701234567','type'=>'supplier','is_active'=>false])->assertRedirect(route('finance.customers.show',$party));$this->assertDatabaseHas('trade_parties',['id'=>$party->id,'type'=>'customer','name'=>'Updated Customer','is_active'=>true]);$this->assertCount(1,$party->fresh()->currencies);$this->assertDatabaseHas('activity_logs',['action'=>'trade_party.updated','subject_id'=>$party->id]);}
 public function test_supplier_profile_edit_rejects_customer_and_duplicate_code():void {[$user]=$this->makeFixtures();$supplier=TradeParty::create(['type'=>'supplier','name'=>'Supplier','code'=>'S-1','is_active'=>true,'created_by'=>$user->id]);$other=TradeParty::create(['type'=>'supplier','name'=>'Other','code'=>'S-2','is_active'=>true,'created_by'=>$user->id]);$this->actingAs($user)->patch(route('finance.customers.update',$supplier),['name'=>'Wrong Route'])->assertNotFound();$this->actingAs($user)->patch(route('finance.suppliers.update',$supplier),['name'=>'Changed','code'=>'S-2'])->assertSessionHasErrors('code');$this->assertSame('Supplier',$supplier->fresh()->name);}
 public function test_customer_creation_redirects_to_customer_page():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Customer Redirect','currency_ids'=>[$afn->id]])->assertRedirect(route('finance.customers.index'));}
 public function test_supplier_creation_redirects_to_supplier_page():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'supplier','name'=>'Supplier Redirect','currency_ids'=>[$afn->id]])->assertRedirect(route('finance.suppliers.index'));}
 public function test_simple_customer_creation_without_currency_input():void {[$user,$afn,$usd]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Simple Customer','phone'=>'0700000000'])->assertRedirect();$party=TradeParty::firstOrFail();$this->assertSame('0700000000',$party->phone);$this->assertCount(2,$party->currencies);}
 public function test_multicurrency_opening_balances_post_balanced_journals():void {[$user,$afn,$usd]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Opening Customer','currency_ids'=>[$afn->id,$usd->id],'opening_date'=>'2026-10-25','opening_balances'=>[$afn->id=>'150.50',$usd->id=>'25.00'],'opening_directions'=>[$afn->id=>'they_owe_us',$usd->id=>'we_owe_them']])->assertRedirect();$this->assertDatabaseCount('trade_opening_balances',2);$this->assertDatabaseCount('journal_entries',2);$this->assertDatabaseHas('trade_opening_balances',['currency_id'=>$usd->id,'amount'=>'-25.0000']);$this->assertDatabaseHas('journal_entries',['status'=>'posted']);}
 public function test_existing_supplier_can_add_opening_balance_once():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'supplier','name'=>'Opening Supplier','currency_ids'=>[$afn->id]])->assertRedirect();$party=TradeParty::firstOrFail();$payload=['currency_id'=>$afn->id,'amount'=>'200','direction'=>'we_owe_them','balance_date'=>'2026-10-25'];$this->actingAs($user)->post(route('finance.parties.opening.store',$party),$payload)->assertRedirect();$this->assertDatabaseCount('trade_opening_balances',1);$this->actingAs($user)->post(route('finance.parties.opening.store',$party),$payload)->assertSessionHasErrors('opening_balances');$this->assertDatabaseCount('journal_entries',1);}
 public function test_customer_credit_balance_uses_advance_ledger():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Advance Customer','currency_ids'=>[$afn->id],'opening_balances'=>[$afn->id=>'75'],'opening_directions'=>[$afn->id=>'we_owe_them'],'opening_date'=>'2026-10-25'])->assertRedirect();$party=TradeParty::firstOrFail();$advance=\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where(['entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$afn->id,'purpose'=>'advance'])->value('ledger_account_id');$this->assertDatabaseHas('journal_lines',['ledger_account_id'=>$advance,'credit'=>'75.0000']);}
 public function test_supplier_advance_uses_advance_ledger():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'supplier','name'=>'Advance Supplier','currency_ids'=>[$afn->id],'opening_balances'=>[$afn->id=>'85'],'opening_directions'=>[$afn->id=>'they_owe_us'],'opening_date'=>'2026-10-25'])->assertRedirect();$party=TradeParty::firstOrFail();$advance=\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where(['entity_type'=>'supplier','entity_id'=>$party->id,'currency_id'=>$afn->id,'purpose'=>'advance'])->value('ledger_account_id');$this->assertDatabaseHas('journal_lines',['ledger_account_id'=>$advance,'debit'=>'85.0000']);}
 public function test_persian_and_arabic_formatted_opening_amounts_are_normalized():void {[$user,$afn]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Localized Digits Customer','currency_ids'=>[$afn->id],'opening_balances'=>[$afn->id=>'۱٬۲۳۴٫۵۰'],'opening_directions'=>[$afn->id=>'they_owe_us'],'opening_date'=>'2026-10-25'])->assertRedirect();$this->assertDatabaseHas('trade_opening_balances',['currency_id'=>$afn->id,'amount'=>'1234.5000']);}
 public function test_blank_opening_amount_creates_no_journal():void {[$user,$afn]=$this->makeFixtures();$before=\Illuminate\Support\Facades\DB::table('journal_entries')->count();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'No Opening Balance Customer','currency_ids'=>[$afn->id],'opening_balances'=>[$afn->id=>''],'opening_directions'=>[$afn->id=>'they_owe_us'],'opening_date'=>'2026-10-25'])->assertRedirect();$this->assertSame($before,\Illuminate\Support\Facades\DB::table('journal_entries')->count());$this->assertDatabaseCount('trade_opening_balances',0);}
 public function test_unselected_opening_currency_rolls_back_creation():void {[$user,$afn,$usd]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Invalid Opening','currency_ids'=>[$afn->id],'opening_date'=>'2026-10-25','opening_balances'=>[$usd->id=>'100']])->assertSessionHasErrors('opening_balances');$this->assertDatabaseCount('trade_parties',0);}
 public function test_invalid_currency_does_not_create_party():void {[$user]=$this->makeFixtures();$this->actingAs($user)->post(route('finance.parties.store'),['type'=>'customer','name'=>'Test','currency_ids'=>[999]])->assertSessionHasErrors('currency_ids.0');$this->assertDatabaseCount('trade_parties',0);}
 public function test_new_customer_register_has_simple_actions_and_no_opening_editor():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Jamil','phone'=>'0700123456','currency_ids'=>[$afn->id,$usd->id],
  ])->assertRedirect();
  $party=TradeParty::sole();
  $this->actingAs($user)->get(route('finance.customers.index'))->assertOk()
   ->assertSee('Customer Register')
   ->assertSee('Jamil')
   ->assertSee('data-edit-party="'.$party->id.'"',false)
   ->assertSee('Statement')
   ->assertSee('WhatsApp')
   ->assertSee('sp-delete-form')
   ->assertDontSee('party-opening-modal-form');
  $this->assertDatabaseCount('trade_opening_balances',0);
 }
 public function test_supplier_register_has_simple_actions():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'supplier','name'=>'Supplier A','currency_ids'=>[$afn->id],
  ])->assertRedirect();
  $this->actingAs($user)->get(route('finance.suppliers.index'))->assertOk()
   ->assertSee('Supplier Register')
   ->assertSee('sp-edit-form')
   ->assertSee('sp-delete-form')
   ->assertSee('WhatsApp');
 }

 public function test_customer_creation_without_opening_balance_starts_with_zero_and_no_journal():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  $this->actingAs($user)->get(route('finance.customers.index'))->assertOk()
   ->assertSee('data-new-entry-form="1"',false)
   ->assertDontSee('party-optional-opening');
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Zero Customer','currency_ids'=>[$afn->id,$usd->id],
  ])->assertRedirect(route('finance.customers.index'));
  $party=TradeParty::where('name','Zero Customer')->firstOrFail();
  $this->assertCount(2,$party->currencies);
  $this->assertDatabaseCount('trade_opening_balances',0);
  $this->assertDatabaseCount('journal_entries',0);
 }
 public function test_supplier_creation_without_opening_balance_has_no_financial_posting():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->get(route('finance.suppliers.index'))->assertOk()
   ->assertSee('data-new-entry-form="1"',false);
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'supplier','name'=>'Zero Supplier','currency_ids'=>[$afn->id],
  ])->assertRedirect(route('finance.suppliers.index'));
  $party=TradeParty::where('name','Zero Supplier')->firstOrFail();
  $this->assertCount(1,$party->currencies);
  $this->assertDatabaseCount('trade_opening_balances',0);
  $this->assertDatabaseCount('journal_entries',0);
 }

 public function test_unused_customer_can_be_deleted_with_ledger_links():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Unused Customer','currency_ids'=>[$afn->id],
  ])->assertRedirect();
  $party=TradeParty::sole();
  $this->actingAs($user)->delete(route('finance.parties.destroy',$party))->assertRedirect(route('finance.customers.index'));
  $this->assertDatabaseCount('trade_parties',0);
  $this->assertDatabaseCount('entity_ledger_accounts',0);
  $this->assertDatabaseHas('activity_logs',['action'=>'trade_party.deleted','subject_id'=>$party->id]);
 }
 public function test_customer_with_journal_history_cannot_be_deleted():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Used Customer','currency_ids'=>[$afn->id],
  ])->assertRedirect();
  $party=TradeParty::sole();
  $ledger=\Illuminate\\Support\\Facades\\DB::table('entity_ledger_accounts')->where([
   'entity_type'=>'customer','entity_id'=>$party->id,'currency_id'=>$afn->id,'purpose'=>'receivable'
  ])->value('ledger_account_id');
  $journal=\App\\Models\\JournalEntry::create([
   'number'=>'JE-T-001','entry_date'=>'2026-11-04','description'=>'Test',
   'status'=>'posted','currency_id'=>$afn->id,'created_by'=>$user->id
  ]);
  $journal->lines()->create(['ledger_account_id'=>$ledger,'debit'=>'100','credit'=>'0']);
  $this->actingAs($user)->delete(route('finance.parties.destroy',$party))->assertSessionHasErrors('party');
  $this->assertDatabaseHas('trade_parties',['id'=>$party->id]);
  $this->actingAs($user)->get(route('finance.parties.statement',$party))->assertOk()
   ->assertSee('100.00')->assertSee('JE-T-001');
 }
 public function test_whatsapp_message_uses_posted_balances_not_draft_amounts():void {
  [$user,$afn]=$this->makeFixtures();
  $party=TradeParty::create(['type'=>'customer','name'=>'Statement Customer','phone'=>'0700123456','is_active'=>true,'created_by'=>$user->id]);
  $party->currencies()->attach($afn->id);
  $party->provisionLedgerAccountsForCurrency($afn->id);
  $id=\Illuminate\\Support\\Facades\\DB::table('entity_ledger_accounts')->where([
   'entity_type'=>'customer','entity_id'=>$party->id,'purpose'=>'receivable'
  ])->value('ledger_account_id');
  $journal=\App\\Models\\JournalEntry::create([
   'number'=>'JE-W-01','entry_date'=>'2026-11-04','description'=>'Paid',
   'status'=>'posted','currency_id'=>$afn->id,'created_by'=>$user->id
  ]);
  $journal->lines()->create(['ledger_account_id'=>$id,'debit'=>'500','credit'=>'0']);
  $draft=\App\\Models\\JournalEntry::create([
   'number'=>'JE-W-02','entry_date'=>'2026-11-04','description'=>'Draft',
   'status'=>'draft','currency_id'=>$afn->id,'created_by'=>$user->id
  ]);
  $draft->lines()->create(['ledger_account_id'=>$id,'debit'=>'999','credit'=>'0']);
  $this->actingAs($user)->get(route('finance.customers.index'))->assertOk()
   ->assertSee('AFN 500.00')
   ->assertDontSee('AFN 1499.00')
   ->assertSee('data-whatsapp-phone="0700123456"',false);
 }

 public function test_party_forms_restore_original_fields_while_opening_balance_remains_optional():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  foreach(['customer'=>'finance.customers.index','supplier'=>'finance.suppliers.index'] as $type=>$route){
   $response=$this->actingAs($user)->get(route($route))->assertOk();
   $body=$response->getContent();
   $this->assertStringContainsString('class="sp-create-form"',$body);
   $form=substr($body,strpos($body,'class="sp-create-form"'));
   $form=substr($form,0,strpos($form,'</form>'));
   foreach(['name="name"','name="phone"','name="code"','name="contact_person"','name="email"','name="address"','name="notes"','name="currency_ids[]"'] as $field){
    $this->assertStringContainsString($field,$form);
   }
   $this->assertStringNotContainsString('opening_balances',$form);
   $this->assertStringContainsString('sp-action-edit',$body);
   $this->assertStringContainsString('sp-action-delete',$body);
   $this->assertStringContainsString('sp-action-statement',$body);
   $this->assertStringContainsString('sp-action-whatsapp',$body);
  }
 }
 public function test_simple_customer_register_has_status_balance_and_all_four_actions():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Jamil','phone'=>'0700123456',
  ])->assertRedirect();
  $party=TradeParty::sole();
  $res=$this->actingAs($user)->get(route('finance.customers.index'))->assertOk();
  $res->assertSee('<th>Name</th><th>Phone</th><th>Status</th><th>Balance</th><th>Actions</th>',false)
   ->assertSee('data-edit-party="'.$party->id.'"',false)
   ->assertSee(route('finance.parties.destroy',$party),false)
   ->assertSee(route('finance.parties.statement',$party),false)
   ->assertSee('data-whatsapp-phone="0700123456"',false)
   ->assertSee('Active')->assertDontSee('Opening Balance');
  $this->assertDatabaseCount('trade_opening_balances',0);
  $this->assertDatabaseCount('journal_entries',0);
 }
 public function test_simple_edit_changes_name_and_phone_without_changing_financial_mappings():void {
  [$user,$afn]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'supplier','name'=>'Original','phone'=>'0700111222',
  ])->assertRedirect();
  $party=TradeParty::sole();
  $before=\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where('entity_type','supplier')->where('entity_id',$party->id)->count();
  $this->actingAs($user)->patch(route('finance.suppliers.update',$party),[
   'name'=>'Renamed','phone'=>'0700999888',
  ])->assertRedirect();
  $this->assertDatabaseHas('trade_parties',['id'=>$party->id,'name'=>'Renamed','phone'=>'0700999888']);
  $this->assertSame($before,\Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where('entity_type','supplier')->where('entity_id',$party->id)->count());
 }

 public function test_restored_party_form_stores_extended_details_without_opening_balance():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Expanded Customer','code'=>'C-504','phone'=>'0700999000',
   'contact_person'=>'Manager','email'=>'contact@example.com','address'=>'Kabul',
   'notes'=>'Preferred contact','currency_ids'=>[$afn->id,$usd->id]
  ])->assertRedirect();
  $party=TradeParty::sole();
  $this->assertDatabaseHas('trade_parties',[
   'id'=>$party->id,'code'=>'C-504','contact_person'=>'Manager',
   'email'=>'contact@example.com','address'=>'Kabul','notes'=>'Preferred contact'
  ]);
  $this->assertCount(2,$party->currencies);
  $this->assertDatabaseCount('trade_opening_balances',0);
  $this->assertDatabaseCount('journal_entries',0);
 }

 public function test_customer_edit_dialog_can_enable_another_currency_without_opening_balance():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'customer','name'=>'Currency Customer','currency_ids'=>[$afn->id]
  ])->assertRedirect();
  $party=TradeParty::sole();
  $this->actingAs($user)->get(route('finance.customers.index'))->assertOk()
   ->assertSee('Add another currency')
   ->assertSee(route('finance.parties.currencies.store',$party),false)
   ->assertSee('name="currency_id"',false);
  $this->actingAs($user)->post(route('finance.parties.currencies.store',$party),[
   'currency_id'=>$usd->id
  ])->assertRedirect();
  $this->assertCount(2,$party->fresh()->currencies);
  $this->assertDatabaseCount('entity_ledger_accounts',4);
  $this->assertDatabaseCount('journal_entries',0);
  $this->assertDatabaseCount('trade_opening_balances',0);
 }
 public function test_cannot_enable_inactive_party_currency():void {
  [$user,$afn,$usd]=$this->makeFixtures();
  $this->actingAs($user)->post(route('finance.parties.store'),[
   'type'=>'supplier','name'=>'Inactive Currency Supplier','currency_ids'=>[$afn->id]
  ])->assertRedirect();
  $party=TradeParty::sole();
  $usd->update(['is_active'=>false]);
  $this->actingAs($user)->post(route('finance.parties.currencies.store',$party),[
   'currency_id'=>$usd->id
  ])->assertSessionHasErrors('currency_id');
  $this->assertCount(1,$party->fresh()->currencies);
 }

}
