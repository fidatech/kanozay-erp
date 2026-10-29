<?php

namespace App\Http\Controllers;

use App\Models\ProductionRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionRecipeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('administration.view'), 403);
        return view('production.recipes', ['recipes' => ProductionRecipe::withCount('lines')->latest('id')->paginate(20)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'product_code' => ['nullable', 'string', 'max:80'],
            'method' => ['required', Rule::in(['liquid_blending', 'powder_blending'])],
            'package_weight_kg' => ['required', 'numeric', 'gt:0'],
            'package_unit' => ['required', 'string', 'max:30'],
            'cost_currency_code' => ['nullable', 'string', 'size:3'],
            'source_sheet' => ['nullable', 'string', 'max:120'],
            'source_reference' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.material_name' => ['required', 'string', 'max:180'],
            'lines.*.unit' => ['required', 'string', 'max:30'],
            'lines.*.quantity_per_package' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.is_packaging' => ['nullable', 'boolean'],
            'lines.*.source_cell' => ['nullable', 'string', 'max:20'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $recipe = ProductionRecipe::create(array_merge(collect($data)->except('lines')->all(), [
                'status' => 'draft', 'created_by' => $request->user()->id,
            ]));
            foreach (array_values($data['lines']) as $index => $line) {
                $recipe->lines()->create(array_merge($line, ['line_number' => $index + 1]));
            }
        });
        return redirect()->route('production.recipes.index')->with('success', 'Draft recipe saved. No stock has been deducted.');
    }
}
