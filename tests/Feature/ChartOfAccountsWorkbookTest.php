<?php
namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\JournalEntry;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ChartOfAccountsWorkbookTest extends TestCase
{
    use RefreshDatabase;
    private function admin():User { $user=User::factory()->create();$user->givePermissionTo([Permission::findOrCreate('administration.view','web'),Permission::findOrCreate('administration.update','web')]);return $user; }
    private function workbook(array $rows):UploadedFile {
        $spreadsheet=new Spreadsheet();$sheet=$spreadsheet->getActiveSheet();$sheet->setTitle('Chart of Accounts');
        $sheet->fromArray(['code','name','type','parent_code','kind','status','description'],null,'A1');
        $sheet->fromArray($rows,null,'A2');$path=tempnam(sys_get_temp_dir(),'coa-test-');(new Xlsx($spreadsheet))->save($path);$spreadsheet->disconnectWorksheets();
        return new UploadedFile($path,'accounts.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }
    public function test_template_download_has_correct_headers_and_view_is_searchable():void {
        $admin=$this->admin();
        $download=$this->actingAs($admin)->get(route('admin.accounts.template'))->assertOk()->assertDownload('Kanozay-COA-Import-Template.xlsx');
        $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($download->baseResponse->getFile()->getPathname());
        $this->assertSame(['code','name','type','parent_code','kind','status','description'],$book->getActiveSheet()->rangeToArray('A1:G1')[0]);
        $book->disconnectWorksheets();
        LedgerAccount::create(['code'=>'A100','name'=>'Findable Assets','type'=>'asset','is_group'=>true]);
        LedgerAccount::create(['code'=>'L100','name'=>'Other Liability','type'=>'liability','is_group'=>true]);
        $this->actingAs($admin)->get(route('admin.accounts.index',['q'=>'Findable']))->assertOk()->assertSee('Findable Assets')->assertDontSee('<td class="coa-code">L100</td>',false)->assertSee('Download Excel Sample')->assertSee('Import Accounts');
    }
    public function test_workbook_imports_groups_before_child_accounts_without_journals():void {
        $user=$this->admin();$file=$this->workbook([
          ['T-1100','Cash','asset','T-1000','posting','active','Main cash'],
          ['T-1000','Assets','asset','','group','active','Asset header'],
        ]);
        $this->actingAs($user)->post(route('admin.accounts.import'),['file'=>$file])->assertRedirect(route('admin.accounts.index'));
        $group=LedgerAccount::where('code','T-1000')->firstOrFail();$child=LedgerAccount::where('code','T-1100')->firstOrFail();
        $this->assertSame($group->id,$child->parent_id);$this->assertTrue($group->is_group);
        $this->assertSame(0,JournalEntry::count());$this->assertDatabaseHas('activity_logs',['action'=>'account.workbook_imported']);
    }
    public function test_workbook_rejects_bad_hierarchy_and_duplicate_existing_account_without_partial_insert():void {
        $user=$this->admin();LedgerAccount::create(['code'=>'A100','name'=>'Existing','type'=>'asset','is_group'=>true]);
        $this->actingAs($user)->post(route('admin.accounts.import'),['file'=>$this->workbook([
          ['T-100','New','asset','','group','active',''],['T-110','Bad child','expense','T-100','posting','active',''],
        ])])->assertSessionHasErrors('file');
        $this->actingAs($user)->post(route('admin.accounts.import'),['file'=>$this->workbook([
          ['T-100','New','asset','','group','active',''],['A100','Existing','asset','','group','active',''],
        ])])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('ledger_accounts',1);
    }
    public function test_delete_unused_account_but_reject_journal_or_child_account():void {
        $user=$this->admin();$unused=LedgerAccount::create(['code'=>'A111','name'=>'Unused','type'=>'asset','is_group'=>false]);
        $this->actingAs($user)->delete(route('admin.accounts.destroy',$unused))->assertRedirect();
        $this->assertDatabaseMissing('ledger_accounts',['id'=>$unused->id]);
        $group=LedgerAccount::create(['code'=>'A100','name'=>'Group','type'=>'asset','is_group'=>true]);
        $child=LedgerAccount::create(['code'=>'A110','name'=>'Child','type'=>'asset','parent_id'=>$group->id,'is_group'=>false]);
        $this->actingAs($user)->delete(route('admin.accounts.destroy',$group))->assertSessionHasErrors('account');
        $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);
        $journal=JournalEntry::create(['number'=>'JE-TEST','entry_date'=>'2026-10-20','description'=>'Test','currency_id'=>$currency->id,'created_by'=>$user->id,'status'=>'draft']);
        $journal->lines()->create(['ledger_account_id'=>$child->id,'debit'=>100,'credit'=>0]);
        $this->actingAs($user)->delete(route('admin.accounts.destroy',$child))->assertSessionHasErrors('account');
        $this->assertDatabaseHas('ledger_accounts',['id'=>$child->id]);
    }
    public function test_unprivileged_users_cannot_import_or_delete():void {
        $user=User::factory()->create();$account=LedgerAccount::create(['code'=>'U100','name'=>'Protected','type'=>'asset']);
        $this->actingAs($user)->post(route('admin.accounts.import'),[])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.accounts.destroy',$account))->assertForbidden();
    }
    public function test_cyclic_parent_references_are_rejected_atomically():void {
        $user=$this->admin();
        $this->actingAs($user)->post(route('admin.accounts.import'),['file'=>$this->workbook([
            ['T-100','Loop A','asset','T-200','group','active',''],
            ['T-200','Loop B','asset','T-100','group','active',''],
        ])])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('ledger_accounts',0);
    }

}
