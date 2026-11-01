<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currencyId = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true])->id;
    }

    private function grant(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
    }

    private function employeeData(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-1001',
            'full_name' => 'Synthetic UAT Employee',
            'father_name' => 'Synthetic Parent',
            'gender' => 'Unspecified',
            'position' => 'UAT Clerk',
            'phone' => '000-000-0000',
            'national_id' => 'SYNTHETIC-NID',
            'taxpayer_id' => 'SYNTHETIC-TAX',
            'monthly_salary' => '12000.0000',
            'salary_currency_id' => $this->currencyId,
        ], $overrides);
    }

    private function payrollWorkbook(string $employeeNumber = 'SYN-1001'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = ['مالیه/حمل', 'معاش ماهانه', 'نمبر تشخیصیه مالیه دهنده', 'شماره تذکره', 'شماره تلیفون', 'وظیفه', 'جنسیت', 'ولد', 'اسم', 'شماره'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'2', $header);
        }
        $sheet->setCellValue('A3', '=(B3-10000)*10%');
        $sheet->setCellValue('B3', 12000);
        $sheet->setCellValue('C3', 'SYNTHETIC-TAX');
        $sheet->setCellValue('D3', 'SYNTHETIC-NID');
        $sheet->setCellValue('E3', '000-000-0000');
        $sheet->setCellValue('F3', 'UAT Clerk');
        $sheet->setCellValue('G3', 'Unspecified');
        $sheet->setCellValue('H3', 'Synthetic Parent');
        $sheet->setCellValue('I3', 'Synthetic Payroll Employee');
        $sheet->setCellValue('J3', $employeeNumber);
        $temporary = tempnam(sys_get_temp_dir(), 'kanozay-payroll-');
        $path = $temporary.'.xlsx';
        unlink($temporary);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function upload(string $path): \Illuminate\Http\UploadedFile
    {
        return new \Illuminate\Http\UploadedFile(
            $path,
            'payroll.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UPLOAD_ERR_OK,
            true,
        );
    }

    public function test_hr_access_is_separate_from_administration_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('workspace.page', ['hr', 'employees']))->assertForbidden();
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertForbidden();
        $this->actingAs($user)->post(route('hr.employees.import-preview'), [])->assertForbidden();
    }

    public function test_authorized_employee_profile_is_saved_with_encrypted_personal_fields(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.view', 'hr.create');

        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();

        $employee = EmployeeProfile::firstOrFail();
        $this->assertSame('Synthetic UAT Employee', $employee->full_name);
        $this->assertSame('SYNTHETIC-NID', $employee->national_id);
        $this->assertNotSame('Synthetic UAT Employee', $employee->getRawOriginal('full_name'));
        $this->assertNotSame('SYNTHETIC-NID', $employee->getRawOriginal('national_id'));
        $this->assertSame('12000.0000', $employee->monthly_salary);
        $this->assertDatabaseHas('activity_logs', ['action' => 'hr.employee.created', 'subject_id' => $employee->id]);
        $this->assertStringNotContainsString('Synthetic UAT Employee', json_encode(ActivityLog::firstOrFail()->details));
        $this->assertStringNotContainsString('SYNTHETIC-NID', json_encode(ActivityLog::firstOrFail()->details));
    }

    public function test_employee_number_must_be_unique_and_salary_precision_is_bounded(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.create');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData(['full_name' => 'Different Person']))->assertSessionHasErrors('employee_number');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData(['employee_number' => 'EMP-1002', 'monthly_salary' => '100.00001']))->assertSessionHasErrors('monthly_salary');
        $this->assertDatabaseCount('employee_profiles', 1);
    }

    public function test_persian_and_arabic_salary_digits_are_normalized(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.create');

        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData([
            'monthly_salary' => '۱۲٬۳۴۵٫۶۷',
        ]))->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-1001', 'monthly_salary' => '12345.6700', 'salary_currency_id' => $this->currencyId]);
    }

    public function test_payroll_workbook_preview_hides_personal_values_and_confirmation_imports_only_after_reupload(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.import');
        $path = $this->payrollWorkbook();

        $preview = $this->actingAs($user)->post(route('hr.employees.import-preview'), ['file' => $this->upload($path), 'salary_currency_id' => $this->currencyId])
            ->assertOk()->assertSee('Rows found:')->assertSee('Valid rows:')->assertSee('source formula cells were found')
            ->assertDontSee('Synthetic Payroll Employee')->assertDontSee('SYNTHETIC-NID');
        preg_match('/name="preview_token" value="([^"]+)"/', $preview->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertDatabaseCount('employee_profiles', 0);

        $this->actingAs($user)->post(route('hr.employees.import-confirm'), [
            'file' => $this->upload($path),
            'preview_token' => $matches[1],
            'salary_currency_id' => $this->currencyId,
            'confirm_import' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $employee = EmployeeProfile::firstOrFail();
        $this->assertSame('Synthetic Payroll Employee', $employee->full_name);
        $this->assertSame('SYNTHETIC-NID', $employee->national_id);
        $this->assertSame('12000.0000', $employee->monthly_salary);
        $this->assertSame($this->currencyId, $employee->salary_currency_id);
        $this->assertDatabaseCount('employee_profiles', 1);

        $this->actingAs($user)->post(route('hr.employees.import-confirm'), [
            'file' => $this->upload($path),
            'preview_token' => $matches[1],
            'salary_currency_id' => $this->currencyId,
            'confirm_import' => '1',
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('employee_profiles', 1);
        @unlink($path);
    }

    public function test_duplicate_employee_numbers_in_payroll_workbook_block_import(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.import');
        $path = $this->payrollWorkbook();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['=(B4-10000)*10%', 12000, 'SYNTHETIC-TAX-2', 'SYNTHETIC-NID-2', '000-000-0001', 'UAT Clerk', 'Unspecified', 'Synthetic Parent', 'Second Synthetic Employee', 'SYN-1001'], null, 'A4');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->actingAs($user)->post(route('hr.employees.import-preview'), ['file' => $this->upload($path), 'salary_currency_id' => $this->currencyId])
            ->assertOk()->assertSee('Employee number is duplicated within this workbook.');
        $this->assertDatabaseCount('employee_profiles', 0);
        @unlink($path);
    }

    public function test_hr_view_filters_directory_and_status_change_does_not_resubmit_personal_data(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'hr.view', 'hr.create', 'hr.update');
        $this->actingAs($user)->post(route('hr.employees.store'), $this->employeeData())->assertRedirect();
        $employee = EmployeeProfile::firstOrFail();

        $this->actingAs($user)->get(route('workspace.page', ['hr', 'employees']))
            ->assertOk()->assertSee('Synthetic UAT Employee')->assertDontSee('SYNTHETIC-NID')->assertDontSee('SYNTHETIC-TAX');
        $this->actingAs($user)->patch(route('hr.employees.update', $employee), ['is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('employee_profiles', ['id' => $employee->id, 'is_active' => 0]);
    }
}
