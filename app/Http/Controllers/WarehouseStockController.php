<?php

namespace App\Http\Controllers;

use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Models\InventoryCostLayer;
use App\Services\WarehouseStockService;
use App\Services\InventoryLotLocationService;
use App\Support\LocalizedDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockController extends Controller
{
    public function index(Request $request,WarehouseStockService $service)
    {
        abort_unless($request->user()->canAny(['inventory.view','administration.view']),403);
        $state=$service->snapshot();
        $layers=InventoryCostLayer::with('material')
            ->where('remaining_quantity','>',0)
            ->where('source_type','inventory_stock_movement')
            ->whereIn('source_id',function($query){
                $query->select('id')->from('inventory_stock_movements')
                    ->where('type','opening')->where('direction','in');
            })
            ->orderBy('received_on')->orderBy('id')->limit(400)->get();
        $positionSums=DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))
            ->get()->groupBy('inventory_cost_layer_id');
        $legacyLots=$layers->map(function($layer)use($positionSums){
            $located=BigDecimal::zero();
            foreach($positionSums->get($layer->id,collect()) as $position){
                $located=$located->plus((string)$position->quantity);
            }
            $missing=BigDecimal::of((string)$layer->remaining_quantity)->minus($located);
            $pool=BigDecimal::zero();
            foreach($positionSums->get($layer->id,collect())
                ->where('location_key','unassigned') as $unknown){
                $pool=$pool->plus((string)$unknown->quantity);
            }
            $layer->setAttribute('unpositioned_quantity',(string)$missing->toScale(6));
            $layer->setAttribute('unassigned_legacy_quantity',(string)$pool->toScale(6));
            $layer->setAttribute('needs_legacy_location_review',
                $missing->isPositive() || $pool->isPositive());
            return $layer;
        })->filter(fn($layer)=>$layer->needs_legacy_location_review)->values();
        $recentMovementIds=DB::table('inventory_warehouse_movements')
            ->orderByDesc('id')->limit(50)->pluck('id');
        $lotMovementDetails=DB::table('inventory_warehouse_lot_movements as moved')
            ->join('inventory_cost_layers as lot','lot.id','=','moved.inventory_cost_layer_id')
            ->whereIn('moved.inventory_warehouse_movement_id',$recentMovementIds)
            ->select('moved.inventory_warehouse_movement_id','moved.inventory_cost_layer_id',
                'moved.quantity','lot.currency_code','lot.source_type','lot.source_id')
            ->orderBy('lot.received_on')->orderBy('lot.id')
            ->get()->groupBy('inventory_warehouse_movement_id');
        $recentLotRows=DB::table('inventory_warehouse_lot_movements')
            ->select('inventory_warehouse_movement_id')
            ->selectRaw('COUNT(*) as lot_count')
            ->groupBy('inventory_warehouse_movement_id')->get()->keyBy('inventory_warehouse_movement_id');
        return view('inventory.warehouse-stock',$state+[
            'legacyLots'=>$legacyLots,
            'recentLotRows'=>$recentLotRows,
            'lotMovementDetails'=>$lotMovementDetails,
            'recent'=>\Illuminate\Support\Facades\DB::table('inventory_warehouse_movements as m')
                ->join('inventory_materials as i','i.id','=','m.inventory_material_id')
                ->join('company_warehouses as d','d.id','=','m.destination_warehouse_id')
                ->leftJoin('company_warehouses as s','s.id','=','m.source_warehouse_id')
                ->select('m.*','i.sku','d.name as destination','s.name as source')
                ->orderByDesc('m.id')->limit(50)->get(),
        ]);
    }
    public function store(Request $request,WarehouseStockService $service)
    {
        abort_unless($request->user()->canAny(['inventory.update','administration.update']),403);
        $request->merge(['quantity'=>$this->normalize($request->input('quantity'))]);
        $data=$request->validate([
            'type'=>['required',Rule::in(['initial_allocation','transfer'])],
            'inventory_material_id'=>['required','integer','exists:inventory_materials,id'],
            'source_warehouse_id'=>['nullable','required_if:type,transfer','integer','exists:company_warehouses,id'],
            'destination_warehouse_id'=>['required','integer','exists:company_warehouses,id'],
            'quantity'=>['required','numeric','gt:0','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/'],
            'occurred_on'=>['required','date','before_or_equal:today'],
            'reason'=>['required','string','min:5','max:500'],
            'idempotency_key'=>['required','uuid'],
        ]);

        $material=InventoryMaterial::findOrFail($data['inventory_material_id']);
        $to=CompanyWarehouse::findOrFail($data['destination_warehouse_id']);
        if($data['type']==='initial_allocation') {
            if(!empty($data['source_warehouse_id'])) {
                return back()->withErrors(['source_warehouse_id'=>'Initial allocations must have no source warehouse.'])->withInput();
            }
            $service->allocate($material,$to,$data,$request->user());
        } else {
            $from=CompanyWarehouse::findOrFail($data['source_warehouse_id']);
            $service->transfer($material,$from,$to,$data,$request->user());
        }
        return redirect()->route('inventory.warehouse-stock.index')->with('success','Warehouse movement recorded without changing company-wide stock totals.');
    }
    public function valuation(Request $request, \App\Services\InventoryCostService $costing)
    {
        abort_unless($request->user()->canAny(['inventory.view','administration.view']),403);
        $filters=$request->validate(['currency'=>['nullable','regex:/^[A-Za-z]{3}$/']]);
        $currency=strtoupper((string)($filters['currency']??''));
        $layers=InventoryCostLayer::with('material')
            ->where('remaining_quantity','>',0)
            ->when($currency!=='',fn($query)=>$query->where('currency_code',$currency))
            ->orderBy('received_on')->orderBy('id')->paginate(40)->withQueryString();
        return view('inventory.warehouse-valuation',[
            'rows'=>$costing->locationValuationRows($layers->getCollection()),
            'layers'=>$layers,
            'currencyFilter'=>$currency,
        ]);
    }

    public function assignLegacy(Request $request, \App\Services\LegacyFifoSourceReviewService $service)
    {
        abort_unless(
            $request->user()->can('administration.update') ||
            ($request->user()->can('inventory.update') && $request->user()->can('finance.update')),
            403
        );
        $request->merge(['quantity'=>LocalizedDecimal::normalize($request->input('quantity'))]);
        $data=$request->validate([
            'inventory_cost_layer_id'=>['required','integer','exists:inventory_cost_layers,id'],
            'company_warehouse_id'=>['required','integer',
                Rule::exists('company_warehouses','id')->where('is_active',true)],
            'quantity'=>['required','numeric','gt:0',
                'regex:/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,6})?$/'],
            'reference'=>['required','string','min:4','max:160'],
            'reason'=>['required','string','min:60','max:2000'],
        ]);
        $service->submit((int)$data['inventory_cost_layer_id'],[
            'review_type'=>'locate_remaining',
            'company_warehouse_id'=>(int)$data['company_warehouse_id'],
            'quantity'=>(string)$data['quantity'],
            'source_evidence_reference'=>$data['reference'],
            'source_reconciliation_notes'=>$data['reason'],
        ],(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')
            ->with('success','Legacy FIFO source/warehouse evidence submitted. Existing stock will not be attributed until a different authorized finance reviewer independently approves the source and physical count.');
    }

    private function normalize(mixed $value):mixed
    {
        if(!is_string($value))return $value;
        return str_replace(',','',strtr($value,[
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٫'=>'.','٬'=>',','،'=>',',
        ]));
    }
}
