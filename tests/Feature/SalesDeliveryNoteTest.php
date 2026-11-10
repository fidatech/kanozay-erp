<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinishedGood;
use App\Models\FinishedGoodDispatch;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\TradeParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesDeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    private function setupInvoice():array
    {
        foreach(['sales.view','sales.create','sales.update'] as $permission)Permission::findOrCreate($permission,'web');
        $user=User::factory()->create();
        $user->givePermissionTo(['sales.view','sales.create','sales.update']);
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $customer=TradeParty::create(['type'=>'customer','name'=>'Delivery customer','is_active'=>true,'created_by'=>$user->id]);
        $recipe=ProductionRecipe::create(['name'=>'Synthetic recipe','method'=>'powder_blending','package_weight_kg'=>5,'package_unit'=>'bag','status'=>'approved']);
        $order=ProductionOrder::create(['number'=>'PRD-DN-1','production_recipe_id'=>$recipe->id,'package_count'=>5,'status'=>'completed']);
        $good=FinishedGood::create(['sku'=>'FG-DN-1','name'=>'Synthetic packages','package_unit'=>'bag','package_weight_kg'=>'5.000']);
        $batch=ProductionBatch::create(['production_order_id'=>$order->id,'finished_good_id'=>$good->id,'batch_number'=>'B-DN-1','reported_packages'=>5]);
        $dispatch=FinishedGoodDispatch::create(['number'=>'DISP-DN-1','submission_token'=>(string)Str::uuid(),'dispatch_date'=>now()->toDateString(),'created_by'=>$user->id]);
        $invoice=SalesInvoice::create([
            'number'=>'INV-DN-1','submission_token'=>(string)Str::uuid(),'customer_id'=>$customer->id,
            'currency_id'=>$currency->id,'currency_code'=>'AFN','invoice_date'=>now()->toDateString(),
            'due_date'=>now()->addDays(7)->toDateString(),'status'=>'posted','subtotal'=>'50',
            'total'=>'50','created_by'=>$user->id,
        ]);
        $line=$invoice->lines()->create([
            'finished_good_id'=>$good->id,'production_batch_id'=>$batch->id,
            'dispatch_id'=>$dispatch->id,'quantity_packages'=>5,'unit_price'=>'10','line_total'=>'50',
        ]);
        return [$user,$invoice,$line];
    }

    public function test_delivery_notes_allow_partial_quantities_and_prevent_double_allocation():void
    {
        [$user,$invoice,$line]=$this->setupInvoice();
        $data=['submission_token'=>(string)Str::uuid(),'sales_invoice_id'=>$invoice->id,
            'delivery_date'=>now()->toDateString(),'quantities'=>[$line->id=>3]];
        $this->actingAs($user)->get(route('workspace.page',['sales','delivery-notes']))->assertOk();
        $this->actingAs($user)->post(route('sales.delivery-notes.store'),$data)->assertRedirect();
        $this->assertDatabaseCount('sales_delivery_notes',1);
        $note=SalesDeliveryNote::firstOrFail();
        $this->assertSame('DN-0000001',$note->number);
        $this->actingAs($user)->get(route('sales.delivery-notes.show',$note))->assertOk()
            ->assertSee('Delivery customer')->assertSee('Synthetic packages');
        $this->actingAs($user)->post(route('sales.delivery-notes.store'),$data)->assertRedirect();
        $this->assertDatabaseCount('sales_delivery_notes',1);
        $data['submission_token']=(string)Str::uuid();
        $this->actingAs($user)->post(route('sales.delivery-notes.store'),$data)->assertSessionHasErrors('quantities');
        $this->assertDatabaseCount('sales_delivery_notes',1);
        $data['submission_token']=(string)Str::uuid();
        $data['quantities'][$line->id]=2;
        $this->actingAs($user)->post(route('sales.delivery-notes.store'),$data)->assertRedirect();
        $this->assertDatabaseCount('sales_delivery_notes',2);
        $this->assertDatabaseCount('journal_entries',0);
        $this->assertDatabaseCount('inventory_stock_movements',0);
    }

    public function test_customer_receipt_and_cancellation_controls_are_audited():void
    {
        [$user,$invoice,$line]=$this->setupInvoice();
        $data=['submission_token'=>(string)Str::uuid(),'sales_invoice_id'=>$invoice->id,
            'delivery_date'=>now()->toDateString(),'quantities'=>[$line->id=>5]];
        $this->actingAs($user)->post(route('sales.delivery-notes.store'),$data)->assertRedirect();
        $note=SalesDeliveryNote::firstOrFail();
        // Customer receipt cannot be confirmed without a proof reference and a location.
        $this->actingAs($user)->post(route('sales.delivery-notes.delivered',$note),[
            'received_by'=>'Synthetic receiver','delivered_at'=>now()->toDateTimeString(),
        ])->assertSessionHasErrors('proof_reference');
        $this->actingAs($user)->post(route('sales.delivery-notes.delivered',$note),[
            'received_by'=>'Synthetic receiver','delivered_at'=>now()->toDateTimeString(),
            'proof_reference'=>'SYN-POD-001',
        ])->assertSessionHasErrors('delivery_location');
        $this->assertNotSame('delivered',$note->fresh()->status);
        $this->actingAs($user)->post(route('sales.delivery-notes.delivered',$note),[
            'received_by'=>'Synthetic receiver','delivered_at'=>now()->toDateTimeString(),
            'proof_reference'=>'SYN-POD-001','delivery_location'=>'Synthetic customer warehouse gate',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('delivered',$note->fresh()->status);
        $this->actingAs($user)->post(route('sales.delivery-notes.cancel',$note),['reason'=>'Incorrect package'])->assertSessionHasErrors('status');
        $stranger=User::factory()->create();
        $this->actingAs($stranger)->get(route('sales.delivery-notes.show',$note))->assertForbidden();
    }
}
