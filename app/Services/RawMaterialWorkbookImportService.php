<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RawMaterialWorkbookImportService
{
    public function preview(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Raw Materials Stock In Factory');
        if (! $sheet) {
            $book->disconnectWorksheets();
            throw ValidationException::withMessages(['file' => 'The workbook must contain the “Raw Materials Stock In Factory” worksheet.']);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        for ($row = 3; $row <= min(1000, $sheet->getHighestDataRow()); $row++) {
            $number = $sheet->getCell('A'.$row)->getCalculatedValue();
            if (! is_numeric($number)) continue;
            $name = trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell('B'.$row)->getFormattedValue()));
            $unit = trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell('F'.$row)->getFormattedValue()));
            $quantity = $this->number($sheet->getCell('G'.$row)->getCalculatedValue());
            $unitCost = $this->number($sheet->getCell('H'.$row)->getCalculatedValue());
            $rowErrors = [];
            if ($name === '') $rowErrors[] = 'Material name is missing.';
            if ($unit === '') $rowErrors[] = 'Stock unit is missing.';
            if ($quantity === null || ! preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', $quantity) || BigDecimal::of($quantity)->compareTo(BigDecimal::zero()) <= 0) $rowErrors[] = 'Quantity must be a positive number with up to six decimal places.';
            if ($unitCost !== null && (! preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', $unitCost) || BigDecimal::of($unitCost)->compareTo(BigDecimal::zero()) < 0)) $rowErrors[] = 'USD unit price must be zero or a positive number with up to six decimal places.';

            $key = mb_strtolower($name.'|'.$unit);
            if ($name !== '' && $unit !== '' && isset($seen[$key])) $rowErrors[] = 'Material name and unit are duplicated within this workbook.';
            $seen[$key] = true;
            if ($name !== '' && $unit !== '' && InventoryMaterial::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->whereRaw('lower(unit) = ?', [mb_strtolower($unit)])->exists()) $rowErrors[] = 'A material with this name and unit already exists.';

            $rows[] = ['source_row' => $row, 'name' => $name, 'unit' => $unit, 'quantity' => $quantity, 'unit_cost_usd' => $unitCost, 'errors' => $rowErrors];
            foreach ($rowErrors as $message) $errors[] = ['row' => $row, 'message' => $message];
        }
        $book->disconnectWorksheets();
        if ($rows === []) throw ValidationException::withMessages(['file' => 'No numbered stock rows were found in the expected worksheet.']);

        return ['rows' => $rows, 'total' => count($rows), 'valid' => count(array_filter($rows, fn ($item) => $item['errors'] === [])), 'errors' => $errors];
    }

    public function import(array $rows, string $date, int $currencyId, User $actor, InventoryStockService $stock): int
    {
        if ($rows === [] || count($rows) > 1000 || collect($rows)->contains(fn ($row) => ! empty($row['errors']))) {
            throw ValidationException::withMessages(['file' => 'The reviewed workbook contains invalid rows. Preview it again after resolving every error.']);
        }
        $currency = Currency::query()->whereKey($currencyId)->where('code', 'USD')->where('is_active', true)->first();
        if (! $currency) throw ValidationException::withMessages(['currency_id' => 'Select an active USD currency to value the source workbook prices.']);

        return DB::transaction(function () use ($rows, $date, $currency, $actor, $stock) {
            foreach ($rows as $item) {
                $name = trim($item['name']);
                $unit = trim($item['unit']);
                if (InventoryMaterial::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->whereRaw('lower(unit) = ?', [mb_strtolower($unit)])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['file' => 'A matching material was added after preview. Nothing was imported; preview the workbook again.']);
                }
                $sku = 'RM-'.strtoupper(substr(hash('sha256', mb_strtolower($name).'|'.mb_strtolower($unit)), 0, 12));
                $material = InventoryMaterial::create(['sku' => $sku, 'name' => $name, 'unit' => $unit, 'is_packaging' => false, 'is_active' => true]);
                $stock->post($material, [
                    'type' => 'opening', 'direction' => 'in', 'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost_usd'],
                    'currency_id' => $item['unit_cost_usd'] !== null ? $currency->id : null,
                    'reference_type' => 'opening', 'reference_id' => $material->id,
                    'occurred_on' => $date, 'notes' => 'Imported from Kanozay raw-material opening stock workbook, source row '.$item['source_row'],
                    'created_by' => $actor->id,
                ]);
            }

            return count($rows);
        });
    }

    private function number(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $text = strtr(trim((string) $value), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>',']);
        $text = str_replace(',', '', $text);
        return is_numeric($text) ? (string) BigDecimal::of($text) : null;
    }
}
