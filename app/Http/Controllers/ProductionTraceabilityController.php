<?php

namespace App\Http\Controllers;

use App\Models\InventoryCostLayer;
use App\Models\ProductionBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionTraceabilityController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()->canAny([
            'manufacturing.view','laboratory.view','inventory.view','administration.view',
        ]),403);
    }

    /**
     * Every issued line is traced to the exact FIFO layer consumed, keeping
     * source receipt, supplier, quantity, original currency and warehouse.
     * An unvalued legacy consumption remains visible with a null lot ID.
     */
    public function batch(Request $request, ProductionBatch $batch)
    {
        $this->guard($request);
        $batch->load(['order.recipe','order.materials','finishedGood','costs','checks']);
        $issues=DB::table('inventory_cost_consumptions as consumed')
            ->join('inventory_stock_movements as movement',
                'movement.id','=','consumed.inventory_stock_movement_id')
            ->join('production_order_materials as item',
                'item.id','=','movement.reference_id')
            ->leftJoin('inventory_cost_layers as lot','lot.id','=','consumed.inventory_cost_layer_id')
            ->leftJoin('goods_receipt_lines as grline',function($join){
                $join->on('grline.id','=','lot.source_id')
                    ->where('lot.source_type','=','goods_receipt_line');
            })
            ->leftJoin('goods_receipts as grn','grn.id','=','grline.goods_receipt_id')
            ->leftJoin('purchase_orders as purchase','purchase.id','=','grn.purchase_order_id')
            ->leftJoin('trade_parties as supplier','supplier.id','=','purchase.supplier_id')
            ->leftJoin('company_warehouses as warehouse',
                'warehouse.id','=','movement.company_warehouse_id')
            ->where('item.production_order_id',$batch->production_order_id)
            ->where('movement.reference_type','production_order_material')
            ->select([
                'consumed.id as consumption_id','consumed.inventory_cost_layer_id','consumed.quantity',
                'consumed.amount','consumed.currency_code','consumed.is_valued',
                'movement.occurred_on','item.id as order_material_id',
                'item.material_name','item.unit','item.is_packaging',
                'lot.source_type','lot.source_id','lot.received_on',
                'grn.number as receipt_number','purchase.number as purchase_number',
                'supplier.name as supplier_name','warehouse.code as warehouse_code',
            ])
            ->orderBy('item.line_number')->orderBy('consumed.id')
            ->paginate(50)->withQueryString();

        $workSteps=DB::table('production_work_steps')
            ->where('production_order_id',$batch->production_order_id)
            ->orderBy('step')->get();

        $dispatched=DB::table('finished_good_dispatch_lines as line')
            ->join('finished_good_dispatches as delivery',
                'delivery.id','=','line.finished_good_dispatch_id')
            ->where('line.production_batch_id',$batch->id)
            ->select(['delivery.number','delivery.dispatch_date','line.quantity_packages',
                'line.company_warehouse_id'])->orderByDesc('line.id')->limit(50)->get();

        $sales=DB::table('sales_invoice_lines as line')
            ->join('sales_invoices as invoice','invoice.id','=','line.sales_invoice_id')
            ->where('line.production_batch_id',$batch->id)
            ->select(['invoice.number','invoice.invoice_date','line.quantity_packages'])
            ->orderByDesc('line.id')->limit(50)->get();

        $lotControl=DB::table('finished_good_lot_controls')
            ->where('production_batch_id',$batch->id)->first();

        return view('production.batch-traceability',compact(
            'batch','issues','workSteps','dispatched','sales','lotControl'
        ));
    }

    /** Find affected batches for a source FIFO lot without guessing its warehouse. */
    public function sourceLot(Request $request, InventoryCostLayer $layer)
    {
        $this->guard($request);
        $layer->load('material');
        $batches=DB::table('inventory_cost_consumptions as consumed')
            ->join('inventory_stock_movements as movement',
                'movement.id','=','consumed.inventory_stock_movement_id')
            ->join('production_order_materials as item',
                'item.id','=','movement.reference_id')
            ->join('production_orders as plan',
                'plan.id','=','item.production_order_id')
            ->leftJoin('production_batches as batch',
                'batch.production_order_id','=','plan.id')
            ->leftJoin('finished_goods as good','good.id','=','batch.finished_good_id')
            ->leftJoin('finished_good_lot_controls as control',
                'control.production_batch_id','=','batch.id')
            ->where('consumed.inventory_cost_layer_id',$layer->id)
            ->where('movement.reference_type','production_order_material')
            ->select([
                'plan.id as order_id','plan.number as order_number',
                'batch.id as batch_id','batch.batch_number','batch.status as batch_status',
                'batch.accepted_packages','good.name as product_name',
                'control.status as lot_control_status','item.material_name','item.is_packaging',
                'consumed.quantity','consumed.currency_code','consumed.amount',
                'movement.occurred_on',
            ])
            ->orderByDesc('movement.id')->paginate(50)->withQueryString();

        $positions=DB::table('inventory_cost_layer_locations')
            ->leftJoin('company_warehouses as warehouse',
                'warehouse.id','=','inventory_cost_layer_locations.company_warehouse_id')
            ->where('inventory_cost_layer_locations.inventory_cost_layer_id',$layer->id)
            ->select(['inventory_cost_layer_locations.quantity',
                'inventory_cost_layer_locations.location_key','warehouse.name as warehouse_name'])
            ->get();

        return view('production.source-lot-traceability',compact('layer','batches','positions'));
    }
}
