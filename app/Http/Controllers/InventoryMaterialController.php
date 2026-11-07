<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\CompanyWarehouse;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostService;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryMaterialController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        return view('inventory.materials', [
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
            'warehouses' => CompanyWarehouse::where('is_active',true)->orderBy('name')->get(),
            'materials' => InventoryMaterial::query()
                ->withExists(['movements as has_opening' => fn ($query) => $query->where('type', 'opening')])
                ->orderBy('name')->paginate(20),
        ]);
    }

    public function show(Request $request, InventoryMaterial $material)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        return view('inventory.material-show', [
            'material' => $material,
            'warehouses' => CompanyWarehouse::where('is_active',true)->orderBy('name')->get(),
            'movements' => $material->movements()->orderByDesc('id')->paginate(25),
        ]);
    }

    public function valuation(Request $request, InventoryCostService $costing)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $currency = strtoupper(trim((string) $request->query('currency', '')));
        if ($currency !== '') abort_if(! preg_match('/^[A-Z]{3}$/', $currency), 422);

        return view('inventory.valuation', [
            'rows' => $costing->valuationRows($currency ?: null),
            'currencyFilter' => $currency,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('inventory.create'), 403);
        $request->merge(['sku' => strtoupper(trim((string) $request->input('sku')))]);
        $data = $request->validate([
            'sku' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,63}$/', Rule::unique('inventory_materials', 'sku')],
            'name' => ['required', 'string', 'max:180'],
            'unit' => ['required', 'string', 'max:30'],
            'is_packaging' => ['required', 'boolean'],
        ]);

        InventoryMaterial::create($data);
        return redirect()->route('inventory.materials.index')->with('success', 'Material created. No stock has been posted.');
    }

    public function opening(Request $request, InventoryMaterial $material, InventoryStockService $stock)
    {
        abort_unless($request->user()->can('inventory.update'), 403);
        $request->merge([
            'quantity' => $this->normalizeQuantity($request->input('quantity')),
            'unit_cost' => $this->normalizeQuantity($request->input('unit_cost')),
        ]);
        $data = $request->validate([
            'quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', 'numeric', 'gt:0'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'company_warehouse_id'=>['nullable',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'unit_cost' => ['nullable', 'required_with:currency_id', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', 'numeric', 'gt:0'],
            'currency_id' => ['nullable', 'required_with:unit_cost', Rule::exists('currencies', 'id')->where('is_active', true)],
        ]);

        $stock->post($material, [
                'type' => 'opening', 'direction' => 'in',
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? null,
                'currency_id' => $data['currency_id'] ?? null,
                'reference_type' => 'opening', 'reference_id' => $material->id,
                'company_warehouse_id'=>$data['company_warehouse_id']??null,
                'occurred_on' => $data['occurred_on'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        return redirect()->route('inventory.materials.index')->with('success', 'Opening stock recorded in the inventory ledger'.(isset($data['unit_cost']) ? ' with its currency-specific cost.' : ' without a valuation snapshot.'));
    }

    public function adjust(Request $request, InventoryMaterial $material, InventoryAdjustmentService $adjustments)
    {
        abort_unless($request->user()->can('inventory.update'), 403);
        $request->merge(['quantity' => $this->normalizeQuantity($request->input('quantity'))]);
        $data = $request->validate([
            'direction' => ['required', Rule::in(['adjustment_in', 'adjustment_out'])],
            'quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', 'numeric', 'gt:0'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'company_warehouse_id'=>['nullable',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $adjustments->post($material, $data, $request->user());

        return redirect()->route('inventory.materials.show', $material)->with('success', 'Stock adjustment posted to the immutable inventory ledger.');
    }

    private function normalizeQuantity(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',', '،' => ',',
        ]);

        return str_replace(',', '', $value);
    }
}
