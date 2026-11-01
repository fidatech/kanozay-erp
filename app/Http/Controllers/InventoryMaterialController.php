<?php

namespace App\Http\Controllers;

use App\Models\InventoryMaterial;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryMaterialController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        return view('inventory.materials', [
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
            'movements' => $material->movements()->orderByDesc('id')->paginate(25),
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
        $data = $request->validate([
            'quantity' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/', 'numeric', 'gt:0'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $stock->post($material, [
            'type' => 'opening', 'direction' => 'in',
            'quantity' => $data['quantity'],
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => $data['occurred_on'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('inventory.materials.index')->with('success', 'Opening stock recorded in the inventory ledger.');
    }
}
