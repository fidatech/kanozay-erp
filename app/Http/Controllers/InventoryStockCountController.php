<?php

namespace App\Http\Controllers;

use App\Models\InventoryMaterial;
use App\Models\CompanyWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\InventoryStockCount;
use App\Services\InventoryStockCountService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryStockCountController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['inventory.view', 'administration.view']), 403);

        $balances=DB::table('inventory_warehouse_balances')->get(['inventory_material_id','company_warehouse_id','quantity']);
        $perMaterial=[];
        foreach($balances as $row){
            $perMaterial[$row->inventory_material_id][$row->company_warehouse_id]=$row->quantity;
        }
        return view('inventory.counts.index', [
            'counts' => InventoryStockCount::with(['creator','warehouse'])->latest('id')->paginate(20),
            'materials' => InventoryMaterial::query()->where('is_active', true)->orderBy('name')->get(),
            'warehouses'=>CompanyWarehouse::where('is_active',true)->orderBy('code')->get(),
            'warehouseBalances'=>$perMaterial,
        ]);
    }

    public function store(Request $request, InventoryStockCountService $service)
    {
        abort_unless($request->user()->can('inventory.create'), 403);

        $quantities = $request->input('quantities', []);
        if (is_array($quantities)) {
            $normalized = [];
            foreach ($quantities as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_string($value)) {
                    $value = str_replace([',', '٬', '،'], '', strtr($value, [
                        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.',
                    ]));
                }
                $normalized[$key] = $value;
            }
            $request->merge(['quantities' => $normalized]);
        }

        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'counted_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'company_warehouse_id'=>['nullable','integer',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'quantities' => ['required', 'array', 'min:1', 'max:100'],
            'quantities.*' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', 'numeric', 'gte:0'],
        ]);

        $count = $service->create($data, $request->user());

        return redirect()->route('inventory.counts.show', $count)
            ->with('success', 'Physical count '.$count->number.' recorded. No stock has moved; review and approve it.');
    }

    public function show(Request $request, InventoryStockCount $count)
    {
        abort_unless($request->user()->canAny(['inventory.view', 'administration.view']), 403);

        return view('inventory.counts.show', [
            'count' => $count->load(['creator','approver','warehouse','lines.material','lines.adjustment']),
        ]);
    }

    public function approve(Request $request, InventoryStockCount $count, InventoryStockCountService $service)
    {
        abort_unless($request->user()->can('inventory.update'), 403);

        $service->approve($count, $request->user());

        return redirect()->route('inventory.counts.show', $count)
            ->with('success', 'Count approved. Every discrepancy has been posted to the immutable stock ledger.');
    }
}
