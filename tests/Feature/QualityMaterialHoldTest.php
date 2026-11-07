<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\GoodsReceipt;
use App\Models\InventoryMaterial;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\QualityMaterialHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QualityMaterialHoldTest extends TestCase {
 use RefreshDatabase;
 public function test_unapproved_receipt_lot_is_held_and_approved_sample_releases_it():void {
  Permission::findOrCreate('inventory.view','web');
  $user=User::factory()->create();$user->givePermissionTo('inventory.view');
  $material=InventoryMaterial::create(['sku'=>'MAT-QC-HOLD','name'=>'Controlled flour','unit'=>'kg']);
  $supplier=TradeParty::create(['type'=>'supplier','name'=>'QC test supplier','is_active'=>true,'created_by'=>$user->id]);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  $order=PurchaseOrder::create(['number'=>'PO-QC-HOLD','supplier_id'=>$supplier->id,
   'currency_id'=>$currency->id,'source'=>'local','order_date'=>today()->subDays(5)->toDateString(),
   'status'=>'confirmed','created_by'=>$user->id]);
  $item=$order->items()->create(['description'=>'Controlled flour','quantity'=>'10',
   'unit_price'=>'12','inventory_material_id'=>$material->id]);
  $this->actingAs($user);
  Permission::findOrCreate('inventory.update','web');$user->givePermissionTo('inventory.update');
  $this->post(route('purchases.orders.receipts.store',$order),[
   'receipt_token'=>(string)Str::uuid(),'received_on'=>today()->subDays(2)->toDateString(),
   'quantities'=>[$item->id=>'10'],
  ])->assertRedirect();
  $receipt=GoodsReceipt::firstOrFail();
  $line=$receipt->lines()->firstOrFail();
  $specId=DB::table('quality_specifications')->insertGetId([
   'subject_type'=>'material','subject_id'=>$material->id,'test_name'=>'Moisture',
   'kind'=>'numeric','minimum'=>'0','maximum'=>'5','unit'=>'%',
   'version'=>1,'is_active'=>true,'approved_by'=>$user->id,'created_at'=>now(),'updated_at'=>now(),
  ]);
  $hold=app(QualityMaterialHoldService::class);
  $this->assertSame('0.000000',(string)$hold->safeAvailable($material->fresh())->toScale(6));
  $stock=app(InventoryStockService::class);
  try{$stock->post($material,['type'=>'issue','direction'=>'out','quantity'=>'1',
    'reference_type'=>'synthetic_production','reference_id'=>91,
    'occurred_on'=>today()->toDateString(),'created_by'=>$user->id]);
    $this->fail('Incoming QC hold should block raw-material issue.');
  }catch(ValidationException $e){$this->assertArrayHasKey('quality_hold',$e->errors());}
  $sampleId=DB::table('quality_samples')->insertGetId([
   'number'=>'QC-SYN-1','submission_token'=>(string)Str::uuid(),
   'source_type'=>'incoming_material','goods_receipt_line_id'=>$line->id,
   'collected_on'=>today()->toDateString(),'status'=>'approved',
   'created_by'=>$user->id,'reviewed_by'=>$user->id,'reviewed_at'=>now(),
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  DB::table('quality_sample_results')->insert([
   'quality_sample_id'=>$sampleId,'quality_specification_id'=>$specId,
   'observed_value'=>'2','outcome'=>'pass','tested_by'=>$user->id,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  $this->assertSame('10.000000',(string)$hold->safeAvailable($material->fresh())->toScale(6));
  $stock->post($material,['type'=>'issue','direction'=>'out','quantity'=>'1',
   'reference_type'=>'synthetic_production','reference_id'=>91,
   'occurred_on'=>today()->toDateString(),'created_by'=>$user->id]);
  $this->assertSame('9.000000',$material->fresh()->on_hand_quantity);
  $this->actingAs($user)->get(route('workspace.page',['inventory','batch-tracking']))
   ->assertOk()->assertSee('QC-SYN-1');
 }
}
