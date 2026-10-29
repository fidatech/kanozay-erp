<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class KanozayWorkbookPreviewController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->can('administration.update'), 403);
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
                    if ((float) $quantity <= 0) $warnings[] = "Row {$row}: nonpositive quantity {$quantity} for {$material}.";
                    $lines[] = [
                        'material' => $material, 'unit' => $unit, 'quantity' => (float) $quantity,
                        'unit_cost' => is_numeric($cost) ? (float) $cost : null, 'cell' => 'F'.$row,
                    ];
                }
                if (!$lines) throw new \RuntimeException('No ingredients found in worksheet.');
                $sum = array_sum(array_map(fn ($line) => strtolower($line['unit']) === 'kg' ? $line['quantity'] : 0, $lines));
                if (abs($sum - $definition['weight']) > 0.01) $warnings[] = 'Total ingredient kg '.round($sum, 4).' differs from package kg '.$definition['weight'].'.';
                $recipes[] = [
                    'name' => $name, 'sheet' => $definition['sheet'], 'method' => $definition['method'],
                    'weight' => $definition['weight'], 'package_unit' => $definition['unit'],
                    'lines' => $lines, 'warnings' => $warnings,
                ];
            }
            $workbook->disconnectWorksheets();
            return view('production.kanozay-workbook-preview', compact('recipes'));
        } catch (\Throwable $exception) {
            return back()->withErrors(['file' => 'Unable to read the expected Kanozay production workbook layout.']);
        }
    }
}
