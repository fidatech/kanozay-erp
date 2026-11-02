<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LegacyAccountsWorkbookReviewService
{
    private const SHEETS = [
        'Ware house#1 Younis' => ['Warehouse stock ledger', null],
        'Warehouse#2' => ['Warehouse stock ledger', null],
        'Warehouse#3 final Product' => ['Finished goods stock ledger', null],
        'Exp PKR' => ['Expense ledger', 'PKR'],
        'Exp AF' => ['Expense ledger', 'AFN'],
        'Exp USD' => ['Expense ledger', 'USD'],
        'AC Receiveable AF' => ['Customer receivables ledger', 'AFN'],
        'AC Receiveable USD' => ['Customer receivables ledger', 'USD'],
        'Daily Production-Batch' => ['Production history', null],
        'AZIZI-Sarafi' => ['Sarafi ledger', null],
        'HM Sarafi' => ['Sarafi ledger', null],
        'Asset' => ['Asset ledger', null],
        'AC-Payable AFN' => ['Supplier payables ledger', 'AFN'],
        'AC-Payable USD' => ['Supplier payables ledger', 'USD'],
        'AIB Bank' => ['Bank ledger', null],
        'Ghazanfar Bank' => ['Bank ledger', null],
    ];

    public function review(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        try {
            if ($workbook->getSheetCount() > 32) {
                throw ValidationException::withMessages(['file' => 'The workbook has more than 32 worksheets. Review its format before uploading.']);
            }
            $sheets = [];
            foreach ($workbook->getAllSheets() as $sheet) {
                $name = $sheet->getTitle();
                $known = self::SHEETS[$name] ?? null;
                $lastRow = $sheet->getHighestDataRow();
                $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                if ($lastRow > 2000 || $lastColumn > 128) {
                    throw ValidationException::withMessages(['file' => 'Worksheet “'.$name.'” exceeds the 2,000-row or 128-column review limit.']);
                }

                $nonempty = 0;
                $possibleDuplicates = 0;
                $fingerprints = [];
                for ($row = 1; $row <= $lastRow; $row++) {
                    $cells = [];
                    for ($column = 1; $column <= $lastColumn; $column++) {
                        $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getValue();
                        $text = trim((string) ($raw ?? ''));
                        if ($text !== '') $cells[$column] = $text;
                    }
                    if (count($cells) < 2) continue;
                    $nonempty++;
                    $fingerprint = hash('sha256', json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if (isset($fingerprints[$fingerprint])) $possibleDuplicates++;
                    $fingerprints[$fingerprint] = true;
                }
                $sheets[] = [
                    'name' => $name,
                    'category' => $known[0] ?? 'Unrecognized worksheet',
                    'currency' => $known[1] ?? null,
                    'nonempty_rows' => $nonempty,
                    'possible_duplicates' => $possibleDuplicates,
                    'recognized' => $known !== null,
                ];
            }

            $names = array_column($sheets, 'name');
            return [
                'sheets' => $sheets,
                'missing' => array_values(array_diff(array_keys(self::SHEETS), $names)),
                'recognized' => count(array_filter($sheets, fn ($sheet) => $sheet['recognized'])),
            ];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
