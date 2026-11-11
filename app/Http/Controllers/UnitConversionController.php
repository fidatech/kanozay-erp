<?php

namespace App\Http\Controllers;

use App\Models\InventoryMaterial;
use App\Models\UnitConversion;
use App\Services\UnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitConversionController extends Controller
{
    private function gate(Request $request, bool $write = false): void
    {
        abort_unless($request->user()->canAny($write
            ? ['inventory.update', 'administration.update']
            : ['inventory.view', 'inventory.update', 'manufacturing.view', 'administration.view']), 403);
    }

    public function index(Request $request)
    {
        $this->gate($request);
        $filter = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $conversions = UnitConversion::with(['material:id,sku,name,unit', 'creator:id,name'])
            ->when($filter['search'] ?? null, function ($query, $search) {
                $query->where(fn ($inner) => $inner->where('from_unit', 'like', "%{$search}%")->orWhere('to_unit', 'like', "%{$search}%"));
            })
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')->orderBy('from_unit')->orderBy('to_unit')->paginate(25)->withQueryString();
        $materials = InventoryMaterial::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit']);
        $canWrite = $request->user()->canAny(['inventory.update', 'administration.update']);

        return view('inventory.unit-conversions', compact('conversions', 'materials', 'filter', 'canWrite'));
    }

    public function store(Request $request, UnitConversionService $service)
    {
        $this->gate($request, true);
        $data = $request->validate([
            'from_unit' => ['required', 'string', 'max:40'],
            'to_unit' => ['required', 'string', 'max:40'],
            'factor' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,10})?$/'],
            'inventory_material_id' => ['nullable', 'integer', Rule::exists('inventory_materials', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $service->define($data, (int) $request->user()->id);

        return redirect()->route('inventory.unit-conversions.index')->with('success', 'Unit conversion saved. It applies to new material links only; existing documents are unchanged.');
    }

    public function deactivate(Request $request, UnitConversion $conversion, UnitConversionService $service)
    {
        $this->gate($request, true);
        $service->deactivate($conversion, (int) $request->user()->id);

        return redirect()->route('inventory.unit-conversions.index')->with('success', 'Unit conversion deactivated. Documents that already used it keep their stored quantities.');
    }
}
