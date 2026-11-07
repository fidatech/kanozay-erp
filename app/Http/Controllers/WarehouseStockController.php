<?php

namespace App\Http\Controllers;

use App\Models\CompanyWarehouse;
use App\Models\InventoryMaterial;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockController extends Controller
{
    public function index(Request $request,WarehouseStockService $service)
    {
        abort_unless($request->user()->canAny(['inventory.view','administration.view']),403);
        $state=$service->snapshot();
        return view('inventory.warehouse-stock',$state+[
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
