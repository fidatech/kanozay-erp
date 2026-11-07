<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\FixedAssetDisposalPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FixedAssetDisposalAccountingTest extends TestCase {
 use RefreshDatabase;
 private function records():array {
  $this->seed(ChartOfAccountsSeeder::class);
  Permission::findOrCreate('finance.update','web');
  Permission::findOrCreate('finance.view','web');
  $user=User::factory()->create();$user->givePermissionTo(['finance.update','finance.view']);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true,'is_active'=>true]);
  $cat=FixedAssetCategory::create(['code'=>'MACH','name'=>'Machines','created_by'=>$user->id]);
  $asset=FixedAsset::create([
   'asset_tag'=>'ASSET-X','name'=>'Synthetic mixer','fixed_asset_category_id'=>$cat->id,
   'acquired_on'=>today()->subYear()->toDateString(),'acquisition_cost'=>'5000',
   'currency_id'=>$currency->id,'source_reference'=>'INV-FA-123','status'=>'active','created_by'=>$user->id,
  ]);
  $ppe=LedgerAccount::where('code','1212')->firstOrFail();
  $offset=LedgerAccount::where('code','3000')->firstOrFail();
  $capital=LedgerAccount::where('code','3200')->firstOrFail();
  $j=JournalEntry::create([
   'number'=>'FA-ORIGIN-1','reference'=>'INV-FA-123',
   'entry_date'=>today()->subYear()->toDateString(),'currency_id'=>$currency->id,
   'status'=>'draft','description'=>'Verified synthetic asset acquisition','created_by'=>$user->id,
  ]);
  $j->lines()->createMany([
   ['ledger_account_id'=>$ppe->id,'debit'=>'5000','credit'=>'0'],
   ['ledger_account_id'=>$capital->id,'debit'=>'0','credit'=>'5000'],
  ]);
  app(JournalPostingService::class)->post($j,$user->id);
  $disposalId=DB::table('fixed_asset_disposals')->insertGetId([
   'fixed_asset_id'=>$asset->id,'disposed_on'=>today()->toDateString(),
   'method'=>'sale','proceeds'=>'1000.0000','accumulated_depreciation'=>'0',
   'book_value'=>'5000.0000','financial_status'=>'pending_reconciliation',
   'reason'=>'Approved physical disposal for synthetic fixture','recorded_by'=>$user->id,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  $asset->update(['status'=>'disposed']);
  return [$user,$asset,$disposalId,$j,$ppe];
 }
 public function test_verified_acquisition_posts_balanced_disposal_with_receivable_and_book_loss():void {
  [$u,$asset,$id,$original,$ppe]=$this->records();
  $data=[
   'acquisition_journal_id'=>$original->id,'asset_ledger_id'=>$ppe->id,
   'receivable_ledger_id'=>LedgerAccount::where('code','1122')->value('id'),
   'loss_ledger_id'=>LedgerAccount::where('code','5700')->value('id'),
   'reconciliation_reference'=>'BOARD-ASSET-APPROVED-1',
  ];
  $journal=app(FixedAssetDisposalPostingService::class)->post($id,$data,$u->id);
  $this->assertSame('posted',$journal->status);
  $this->assertDatabaseHas('fixed_asset_disposal_postings',['fixed_asset_disposal_id'=>$id,
    'gain_or_loss'=>'-4000.0000']);
  $this->assertDatabaseHas('fixed_asset_disposals',['id'=>$id,'financial_status'=>'posted']);
  $this->assertDatabaseCount('journal_entries',2);
  $this->actingAs($u)->get(route('workspace.page',['assets','disposals']))
   ->assertOk()->assertSee('BOARD-ASSET-APPROVED-1');
  try{app(FixedAssetDisposalPostingService::class)->post($id,$data,$u->id);
   $this->fail('Duplicate posting should be blocked.');
  }catch(ValidationException $e){$this->assertArrayHasKey('disposal',$e->errors());}
 }
 public function test_wrong_acquisition_reference_rejects_disposal_posting():void {
  [$u,$asset,$id,$original,$ppe]=$this->records();
  $original->update(['reference'=>'UNRELATED-ASSET-REF']);
  try{app(FixedAssetDisposalPostingService::class)->post($id,[
   'acquisition_journal_id'=>$original->id,'asset_ledger_id'=>$ppe->id,
   'loss_ledger_id'=>LedgerAccount::where('code','5700')->value('id'),
   'reconciliation_reference'=>'BOARD-ASSET-APPROVED-2'], $u->id);
   $this->fail('Unrelated asset acquisition must be rejected.');
  }catch(ValidationException $e){$this->assertArrayHasKey('acquisition_journal_id',$e->errors());}
  $this->assertDatabaseCount('fixed_asset_disposal_postings',0);
 }
}
