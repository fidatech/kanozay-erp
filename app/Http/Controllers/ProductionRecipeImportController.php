<?php

namespace App\Http\Controllers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductionRecipeImportController extends Controller
{
    public function preview(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.update', 'manufacturing.update']), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048']]);
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($request->file('file')->getRealPath());
                $reader->setReadDataOnly(true);
                $workbook = $reader->load($request->file('file')->getRealPath());
                $sheet = $workbook->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);
                $workbook->disconnectWorksheets();
                $handle = fopen('php://temp', 'w+');
                foreach ($rows as $row) fputcsv($handle, $row);
                rewind($handle);
            } catch (\Throwable $e) {
                return back()->withErrors(['file' => 'Excel file could not be read. Export the normalized recipe template and try again.']);
            }
        } else {
            $handle = fopen($request->file('file')->getRealPath(), 'rb');
        }
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
                $weights=BigDecimal::zero();foreach($group as $row)if(strtolower(trim($row['unit']))==='kg')$weights=$weights->plus((string)$row['quantity_per_package']);
                if ($weights->minus((string)$first['package_weight_kg'])->abs()->compareTo(BigDecimal::of('0.01')) > 0) $errors[] = $first['recipe_name'].': ingredient kg total '.(string)$weights->toScale(4,RoundingMode::HalfUp).' differs from package weight '.$first['package_weight_kg'].' kg (check packaging and water quantities).';
                return ['name' => $first['recipe_name'], 'method' => $first['method'], 'count' => $group->count(), 'weight' => (string)$weights->toScale(4,RoundingMode::HalfUp), 'package' => $first['package_weight_kg']];
            })->values();
            return view('production.recipe-import-preview', ['summaries' => $summaries, 'errors' => $errors, 'rowCount' => count($rows)]);
        } finally { fclose($handle); }
    }
}
