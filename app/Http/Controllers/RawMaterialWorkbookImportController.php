<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Services\InventoryStockService;
use App\Services\RawMaterialWorkbookImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RawMaterialWorkbookImportController extends Controller
{
    public function preview(Request $request, RawMaterialWorkbookImportService $importer)
    {
        abort_unless($request->user()->canAny(['administration.update', 'inventory.update']), 403);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:4096']]);
        $preview = $importer->preview($data['file']->getRealPath());
        $request->session()->put('raw_material_workbook_preview', $preview['rows']);

        return view('inventory.import-preview', ['preview' => $preview, 'hasUsd' => Currency::where('code', 'USD')->where('is_active', true)->exists()]);
    }

    public function confirm(Request $request, RawMaterialWorkbookImportService $importer, InventoryStockService $stock)
    {
        abort_unless($request->user()->canAny(['administration.update', 'inventory.update']), 403);
        $data = $request->validate([
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
            'confirm_import' => ['accepted'],
        ]);
        $rows = $request->session()->get('raw_material_workbook_preview');
        if (! is_array($rows) || $rows === []) return redirect()->route('inventory.materials.index')->withErrors(['file' => 'Preview the original workbook again before confirming.']);

        $count = DB::transaction(function () use ($request, $data, $rows, $importer, $stock) {
            $count = $importer->import($rows, $data['occurred_on'], (int) $data['currency_id'], $request->user(), $stock);
            ActivityLog::record('inventory.materials.imported', 'inventory_material', null, 'Imported opening raw material stock from reviewed client workbook', ['record_count' => $count]);
            return $count;
        });
        $request->session()->forget('raw_material_workbook_preview');

        return redirect()->route('inventory.materials.index')->with('success', $count.' materials and opening stock movements imported. Source USD prices were retained as cost layers.');
    }
}
