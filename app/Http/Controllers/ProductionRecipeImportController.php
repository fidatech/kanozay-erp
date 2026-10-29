<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductionRecipeImportController extends Controller
{
    public function preview(Request $request)
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        if (!$handle) return back()->withErrors(['file' => 'Could not read the uploaded CSV.']);
        try {
            $header = fgetcsv($handle);
            $expected = ['recipe_name', 'method', 'package_weight_kg', 'package_unit', 'material_name', 'unit', 'quantity_per_package', 'unit_cost', 'source_sheet', 'source_cell'];
            $header = array_map(fn ($value) => trim((string) $value), $header ?: []);
            if ($header !== $expected) return back()->withErrors(['file' => 'CSV columns must exactly match the template order.']);
            $rows = []; $errors = []; $rowNumber = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($rowNumber > 501) { $errors[] = 'Maximum 500 ingredient rows per preview.'; break; }
                if (count($values) !== count($expected)) { $errors[] = "Row {$rowNumber}: incorrect column count."; continue; }
                $row = array_combine($expected, $values);
                $validation = Validator::make($row, [
                    'recipe_name' => 'required|string|max:180',
                    'method' => 'required|in:liquid_blending,powder_blending',
                    'package_weight_kg' => 'required|numeric|gt:0',
                    'package_unit' => 'required|string|max:30',
                    'material_name' => 'required|string|max:180',
                    'unit' => 'required|string|max:30',
                    'quantity_per_package' => 'required|numeric|gt:0',
                    'unit_cost' => 'nullable|numeric|gte:0',
                    'source_sheet' => 'nullable|string|max:120',
                    'source_cell' => 'nullable|string|max:20',
                ]);
                if ($validation->fails()) { $errors[] = "Row {$rowNumber}: ".$validation->errors()->first(); continue; }
                $rows[] = $row;
            }
            $groups = collect($rows)->groupBy(fn ($row) => implode('|', [$row['recipe_name'], $row['method'], $row['package_weight_kg'], $row['package_unit']]));
            $summaries = $groups->map(function ($group) use (&$errors) {
                $first = $group->first();
                $weights = $group->filter(fn ($row) => strtolower(trim($row['unit'])) === 'kg')->sum(fn ($row) => (float) $row['quantity_per_package']);
                if (abs($weights - (float) $first['package_weight_kg']) > 0.01) $errors[] = $first['recipe_name'].': ingredient kg total '.round($weights, 4).' differs from package weight '.$first['package_weight_kg'].' kg (check packaging and water quantities).';
                return ['name' => $first['recipe_name'], 'method' => $first['method'], 'count' => $group->count(), 'weight' => round($weights, 4), 'package' => $first['package_weight_kg']];
            })->values();
            return view('production.recipe-import-preview', ['summaries' => $summaries, 'errors' => $errors, 'rowCount' => count($rows)]);
        } finally { fclose($handle); }
    }
}
