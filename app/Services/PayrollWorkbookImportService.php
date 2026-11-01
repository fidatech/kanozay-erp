<?php

namespace App\Services;

use App\Models\EmployeeProfile;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PayrollWorkbookImportService
{
    private const HEADERS = [
        'مالیه/حمل' => null,
        'معاش ماهانه' => 'monthly_salary',
        'نمبر تشخیصیه مالیه دهنده' => 'taxpayer_id',
        'شماره تذکره' => 'national_id',
        'شماره تلیفون' => 'phone',
        'وظیفه' => 'position',
        'جنسیت' => 'gender',
        'ولد' => 'father_name',
        'اسم' => 'full_name',
        'شماره' => 'employee_number',
    ];

    public function preview(string $path, int $salaryCurrencyId): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $mapping = $this->mapping($sheet);
        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow > 512) {
            throw ValidationException::withMessages(['file' => 'The payroll workbook exceeds the 500 employee row limit.']);
        }

        $rows = [];
        $errors = [];
        $formulaCount = 0;
        $seenNumbers = [];
        for ($rowNumber = 3; $rowNumber <= $lastRow; $rowNumber++) {
            $source = [];
            $hasData = false;
            $rowErrors = [];
            foreach ($mapping as ['field' => $field, 'column' => $column]) {
                if ($field === null) {
                    $formulaCount += str_starts_with((string) $sheet->getCell($column.$rowNumber)->getValue(), '=') ? 1 : 0;
                    continue;
                }
                $cell = $sheet->getCell($column.$rowNumber);
                if (is_string($cell->getValue()) && str_starts_with($cell->getValue(), '=')) {
                    $value = '';
                    $rowErrors[] = 'Formula cells are not accepted for employee fields.';
                } else {
                    $value = $this->cellText($sheet, $column, $rowNumber);
                }
                $source[$field] = $value;
                $hasData = $hasData || $value !== '';
            }
            if (! $hasData) {
                continue;
            }

            $source['employee_number'] = trim($source['employee_number'] ?? '');
            $source['full_name'] = trim($source['full_name'] ?? '');
            $source['monthly_salary'] = $this->normalizeNumber($source['monthly_salary'] ?? '');
            $rowErrors = array_merge($rowErrors, $this->validateRow($source));
            $number = $source['employee_number'];
            if ($number !== '' && isset($seenNumbers[$number])) {
                $rowErrors[] = 'Employee number is duplicated within this workbook.';
            }
            if ($number !== '') {
                $seenNumbers[$number] = true;
            }
            $rows[$rowNumber] = ['data' => $source, 'errors' => $rowErrors];
            foreach ($rowErrors as $error) {
                $errors[] = ['row' => $rowNumber, 'message' => $error];
            }
        }

        $existing = EmployeeProfile::query()->whereIn('employee_number', collect($rows)->pluck('data.employee_number')->filter()->all())->pluck('employee_number')->flip();
        foreach ($rows as $rowNumber => &$row) {
            if (isset($existing[$row['data']['employee_number']])) {
                $row['errors'][] = 'Employee number already exists in the register.';
                $errors[] = ['row' => $rowNumber, 'message' => 'Employee number already exists in the register.'];
            }
        }
        unset($row);
        foreach ($rows as &$row) {
            $row['data']['salary_currency_id'] = $salaryCurrencyId;
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => count($rows),
            'valid' => count(array_filter($rows, fn ($row) => $row['errors'] === [])),
            'errors' => $errors,
            'formula_count' => $formulaCount,
        ];
    }

    public function confirm(string $path, string $token, int $salaryCurrencyId, User $actor): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4 || ! ctype_digit($parts[0]) || time() - (int) $parts[0] > 3600) {
            throw ValidationException::withMessages(['preview_token' => 'This preview expired. Upload the workbook again.']);
        }

        $fingerprint = hash_file('sha256', $path);
        $payload = $parts[0].'.'.$fingerprint.'.'.$salaryCurrencyId;
        $expected = hash_hmac('sha256', $payload, config('app.key'));
        if (! hash_equals($parts[1], $fingerprint) || ! hash_equals($parts[2], (string) $salaryCurrencyId) || ! hash_equals($parts[3], $expected)) {
            throw ValidationException::withMessages(['file' => 'The selected workbook differs from the reviewed preview. Upload it again and review the new preview.']);
        }

        $preview = $this->preview($path, $salaryCurrencyId);
        if ($preview['total'] === 0 || $preview['errors'] !== []) {
            throw ValidationException::withMessages(['file' => 'Resolve workbook errors and preview it again before importing.']);
        }

        return DB::transaction(function () use ($preview, $actor) {
            foreach ($preview['rows'] as $row) {
                EmployeeProfile::create($row['data'] + ['created_by' => $actor->id]);
            }

            return $preview['total'];
        });
    }

    public function tokenFor(string $path, int $salaryCurrencyId): string
    {
        $timestamp = (string) time();
        $fingerprint = hash_file('sha256', $path);
        $payload = $timestamp.'.'.$fingerprint.'.'.$salaryCurrencyId;

        return $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
    }

    private function mapping(Worksheet $sheet): array
    {
        $mapping = [];
        foreach (self::HEADERS as $header => $field) {
            $column = null;
            for ($index = 1; $index <= 20; $index++) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($index).'2');
                $candidate = trim((string) $cell->getFormattedValue());
                if ($candidate === $header) {
                    $column = $cell->getColumn();
                    break;
                }
            }
            if ($column === null) {
                throw ValidationException::withMessages(['file' => 'The workbook headers do not match the reviewed Kanozay payroll layout. No records were imported.']);
            }
            $mapping[] = ['field' => $field, 'column' => $column];
        }
        return $mapping;
    }

    private function cellText(Worksheet $sheet, string $column, int $row): string
    {
        $value = $sheet->getCell($column.$row)->getFormattedValue();
        return $value === null ? '' : trim((string) $value);
    }

    private function validateRow(array $data): array
    {
        $errors = [];
        if ($data['employee_number'] === '' || strlen($data['employee_number']) > 32 || ! preg_match('/^[A-Za-z0-9._-]+$/', $data['employee_number'])) {
            $errors[] = 'Employee number is missing or invalid.';
        }
        if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 180) {
            $errors[] = 'Employee name is missing or too long.';
        }
        try {
            $salary = BigDecimal::of($data['monthly_salary']);
            $salaryIsValid = preg_match('/^\d+(?:\.\d{1,4})?$/D', $data['monthly_salary']) === 1
                && $salary->compareTo(BigDecimal::zero()) >= 0
                && $salary->compareTo(BigDecimal::of('999999999999.9999')) <= 0;
        } catch (\Throwable) {
            $salaryIsValid = false;
        }
        if (! $salaryIsValid) {
            $errors[] = 'Monthly salary is missing or invalid.';
        }
        foreach (['father_name' => 180, 'gender' => 32, 'position' => 180, 'phone' => 80, 'national_id' => 100, 'taxpayer_id' => 100] as $field => $maximum) {
            if (mb_strlen($data[$field] ?? '') > $maximum) {
                $errors[] = 'A value in the '.$field.' field is too long.';
            }
        }

        return $errors;
    }

    private function normalizeNumber(string $value): string
    {
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',', '،' => ',',
        ]);

        return str_replace(',', '', $value);
    }
}
