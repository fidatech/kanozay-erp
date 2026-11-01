<?php

namespace App\Http\Controllers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ProductionRecipe;
use PhpOffice\PhpSpreadsheet\IOFactory;

class KanozayWorkbookPreviewController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.update', 'manufacturing.update']), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:4096']]);
        try {
            $reader = IOFactory::createReaderForFile($request->file('file')->getRealPath());
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($request->file('file')->getRealPath());
            $recipes = [];
            foreach ([
                ['sheet' => 'Production Method 1', 'name_row' => 2, 'header_row' => 3, 'weight' => 20, 'method' => 'liquid_blending', 'unit' => 'can'],
                ['sheet' => 'Production Method 2', 'name_row' => 4, 'header_row' => 5, 'weight' => 25, 'method' => 'powder_blending', 'unit' => 'bag'],
            ] as $definition) {
                $sheet = $workbook->getSheetByName($definition['sheet']);
                if (!$sheet) throw new \RuntimeException('Required worksheet missing.');
                $name = trim((string) $sheet->getCell('B'.$definition['name_row'])->getValue());
                if (!$name || stripos((string) $sheet->getCell('B'.$definition['header_row'])->getValue(), 'Details') === false) {
                    throw new \RuntimeException('Workbook layout does not match Kanozay production template.');
                }
                $lines = [];
                $warnings = [];
                for ($row = $definition['header_row'] + 1; $row <= min(200, $sheet->getHighestRow()); $row++) {
                    $number = $sheet->getCell('A'.$row)->getValue();
                    if (!is_numeric($number)) continue;
                    $material = trim((string) $sheet->getCell('B'.$row)->getValue());
                    $quantity = $sheet->getCell('F'.$row)->getCalculatedValue();
                    if (!$material || !is_numeric($quantity)) {
                        $warnings[] = "Row {$row}: missing ingredient or numeric dose.";
                        continue;
                    }
                    $unit = trim((string) $sheet->getCell('C'.$row)->getValue());
                    $cost = $sheet->getCell('E'.$row)->getCalculatedValue();
                    $quantityValue=BigDecimal::of((string)$quantity);
                    if ($quantityValue->compareTo(BigDecimal::zero()) <= 0) $warnings[] = "Row {$row}: nonpositive quantity {$quantity} for {$material}.";
                    $costValue=is_numeric($cost)?(string)BigDecimal::of((string)$cost)->toScale(4,RoundingMode::HalfUp):null;
                    $lines[] = [
                        'material' => $material, 'unit' => $unit, 'quantity' => (string)$quantityValue->toScale(6,RoundingMode::HalfUp),
                        'unit_cost' => $costValue, 'cell' => 'F'.$row,
                    ];
                }
                if (!$lines) throw new \RuntimeException('No ingredients found in worksheet.');
                $sum=BigDecimal::zero();foreach($lines as $line)if(strtolower($line['unit'])==='kg')$sum=$sum->plus($line['quantity']);
                if ($sum->minus((string)$definition['weight'])->abs()->compareTo(BigDecimal::of('0.01')) > 0) $warnings[] = 'Total ingredient kg '.(string)$sum->toScale(4,RoundingMode::HalfUp).' differs from package kg '.$definition['weight'].'.';
                $recipes[] = [
                    'name' => $name, 'sheet' => $definition['sheet'], 'method' => $definition['method'],
                    'weight' => $definition['weight'], 'package_unit' => $definition['unit'],
                    'lines' => $lines, 'warnings' => $warnings,
                ];
            }
            $workbook->disconnectWorksheets();
            // Keep the parsed snapshot server-side. Confirmation never trusts browser-submitted ingredient data.
            $request->session()->put('kanozay_recipe_preview', $recipes);
            return view('production.kanozay-workbook-preview', compact('recipes'));
        } catch (\Throwable $exception) {
            return back()->withErrors(['file' => 'Unable to read the expected Kanozay production workbook layout.']);
        }
    }
    public function confirm(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.update', 'manufacturing.update']), 403);
        $recipes = $request->session()->get('kanozay_recipe_preview');
        if (!is_array($recipes) || count($recipes) !== 2) {
            return redirect()->route('production.recipes.index')->withErrors(['file' => 'Preview the original workbook again before confirming.']);
        }
        foreach ($recipes as $recipe) {
            if (!empty($recipe['warnings'])) {
                return redirect()->route('production.recipes.index')->withErrors(['file' => 'Import blocked: resolve all workbook warnings and preview the corrected file again.']);
            }
            if (empty($recipe['lines']) || count($recipe['lines']) > 100) {
                return redirect()->route('production.recipes.index')->withErrors(['file' => 'Import blocked: invalid ingredient count.']);
            }
            foreach ($recipe['lines'] as $line) {
                if (!is_numeric($line['quantity']) || BigDecimal::of((string)$line['quantity'])->compareTo(BigDecimal::zero()) <= 0 || !trim($line['material']) || !trim($line['unit'])) {
                    return redirect()->route('production.recipes.index')->withErrors(['file' => 'Import blocked: invalid ingredient values.']);
                }
            }
        }
        DB::transaction(function () use ($recipes, $request) {
            foreach ($recipes as $recipe) {
                $record = ProductionRecipe::create([
                    'name' => $recipe['name'], 'method' => $recipe['method'],
                    'package_weight_kg' => $recipe['weight'], 'package_unit' => $recipe['package_unit'],
                    'cost_currency_code' => $recipe['method'] === 'powder_blending' ? 'USD' : null,
                    'source_sheet' => $recipe['sheet'],
                    'source_reference' => 'Kanozay production workbook (user-uploaded)',
                    'status' => 'draft', 'created_by' => $request->user()->id,
                ]);
                foreach ($recipe['lines'] as $index => $line) {
                    $record->lines()->create([
                        'line_number' => $index + 1, 'material_name' => $line['material'],
                        'unit' => $line['unit'], 'quantity_per_package' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'], 'source_cell' => $line['cell'],
                    ]);
                }
            }
        });
        $request->session()->forget('kanozay_recipe_preview');
        return redirect()->route('production.recipes.index')->with('success', 'Validated workbook recipes saved as drafts. No inventory or accounting entries posted.');
    }

}
