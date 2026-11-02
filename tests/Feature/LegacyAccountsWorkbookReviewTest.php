<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LegacyAccountsWorkbookReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_administrators_can_review_ledger_structure_without_posting(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Exp USD');
        $sheet->fromArray(['NO', 'DATE', 'DESCRIPTION', 'DEBIT', 'CREDIT', 'BALANCE'], null, 'A1');
        $sheet->fromArray([1, '2026-01-01', 'Synthetic test', 10, 0, 10], null, 'A2');
        $sheet->fromArray([1, '2026-01-01', 'Synthetic test', 10, 0, 10], null, 'A3');
        $book->createSheet()->setTitle('Unknown sheet');
        $path = tempnam(sys_get_temp_dir(), 'kanozay-accounts-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('admin.accounts.workbook-review'), [
            'file' => new UploadedFile($path, 'accounts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertForbidden();

        Permission::findOrCreate('administration.update', 'web');
        $user->givePermissionTo('administration.update');
        $this->actingAs($user)->post(route('admin.accounts.workbook-review'), [
            'file' => new UploadedFile($path, 'accounts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->assertSee('Exp USD')->assertSee('Possible repeated rows')->assertSee('Unknown sheet')->assertSee('Missing expected worksheets');

        $this->assertSame(0, JournalEntry::count());
    }
}
