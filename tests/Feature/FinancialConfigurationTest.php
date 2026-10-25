<?php
namespace Tests\Feature;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\FinancialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class FinancialConfigurationTest extends TestCase {
 use RefreshDatabase;
 private function admin(): User { $u=User::factory()->create(); Permission::findOrCreate('administration.view','web');Permission::findOrCreate('administration.update','web');$u->givePermissionTo(['administration.view','administration.update']);return $u; }
 public function test_unauthorized_users_cannot_access_financial_configuration():void { $u=User::factory()->create();$this->actingAs($u)->get(route('admin.financial-settings.edit'))->assertForbidden();$this->actingAs($u)->get(route('admin.sequences.index'))->assertForbidden(); }
 public function test_financial_settings_save_without_creating_duplicate_records():void { $u=$this->admin();$currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);$data=['base_currency_id'=>$currency->id,'fiscal_year_start'=>'01-01','allow_negative_stock'=>0,'require_payment_approval'=>1,'require_purchase_approval'=>1,'purchase_mode'=>'both'];$this->actingAs($u)->get(route('admin.financial-settings.edit'))->assertOk();$this->actingAs($u)->put(route('admin.financial-settings.update'),$data)->assertRedirect();$this->actingAs($u)->put(route('admin.financial-settings.update'),array_merge($data,['purchase_mode'=>'local']))->assertRedirect();$this->assertDatabaseCount('financial_settings',1);$this->assertSame('local',FinancialSetting::firstOrFail()->purchase_mode);$this->actingAs($u)->put(route('admin.financial-settings.update'),array_merge($data,['fiscal_year_start'=>'02-30']))->assertSessionHasErrors('fiscal_year_start'); }
 public function test_document_sequences_issue_distinct_numbers():void { $u=$this->admin();$this->actingAs($u)->get(route('admin.sequences.index'))->assertOk();$this->actingAs($u)->post(route('admin.sequences.store'),['document_type'=>'purchase_order','prefix'=>'PO-','next_number'=>1,'padding'=>5])->assertRedirect();$this->assertSame('PO-00001',DocumentSequence::issue('purchase_order'));$this->assertSame('PO-00002',DocumentSequence::issue('purchase_order'));$this->assertSame(3,DocumentSequence::firstOrFail()->next_number);$this->assertSame('PO-',DocumentSequence::firstOrFail()->prefix); }
}
