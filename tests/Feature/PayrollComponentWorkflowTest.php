<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\LedgerAccount;
use App\Models\PayrollLineComponent;
use App\Models\User;
use App\Services\PayrollPeriodService;
use App\Services\PayrollComponentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollComponentWorkflowTest extends TestCase {
 use RefreshDatabase;
 private function records():array {
  $this->seed(ChartOfAccountsSeeder::class);
  foreach(['hr.view','hr.update','finance.update'] as $p)Permission::findOrCreate($p,'web');
  $actor=User::factory()->create();
  $actor->givePermissionTo(['hr.view','hr.update','finance.update']);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true,'is_active'=>true]);
  EmployeeProfile::create(['employee_number'=>'EMP-COMP-1','full_name'=>'Synthetic Payroll Employee',
   'position'=>'Production test','monthly_salary'=>'100','salary_currency_id'=>$currency->id,
   'is_active'=>true,'created_by'=>$actor->id]);
  $period=app(PayrollPeriodService::class)->create(now()->subMonth()->format('Y-m'),$currency->id,$actor->id);
  return [$actor,$period,$period->lines()->firstOrFail()];
 }
 public function test_payroll_components_preserve_gross_and_liability_and_post_balanced_journal():void {
  [$u,$p,$line]=$this->records();
  $allowance=[
   'submission_token'=>(string)Str::uuid(),'kind'=>'allowance',
   'label'=>'Transport allowance','amount'=>'20',
   'ledger_account_id'=>LedgerAccount::where('code','5460')->value('id'),
   'approval_reference'=>'APPROVAL-SYN-1','reason'=>'Specifically approved monthly allowance',
  ];
  $this->actingAs($u)->post(route('hr.payroll.components.store',[$p,$line]),$allowance)->assertRedirect();
  $deduction=[
   'submission_token'=>(string)Str::uuid(),'kind'=>'deduction',
   'label'=>'Reviewed withholding','amount'=>'10',
   'ledger_account_id'=>LedgerAccount::where('code','2132')->value('id'),
   'approval_reference'=>'APPROVAL-SYN-2','reason'=>'Approved company withholding rule',
  ];
  $this->actingAs($u)->post(route('hr.payroll.components.store',[$p,$line]),$deduction)->assertRedirect();
  $this->assertSame('110.0000',$line->fresh()->payable_amount);
  $this->assertDatabaseCount('payroll_line_components',2);
  $this->actingAs($u)->post(route('hr.payroll.approve',$p),[
   'expense_ledger_id'=>LedgerAccount::where('code','5410')->value('id'),
   'payable_ledger_id'=>LedgerAccount::where('code','2131.01')->value('id'),
   'confirm_review'=>1,
  ])->assertRedirect();
  $this->assertSame('approved',$p->fresh()->status);
  $this->assertSame('posted',$p->fresh()->approvalJournal->status);
  $this->assertDatabaseCount('journal_lines',4);
  $this->actingAs($u)->get(route('hr.payroll.lines.payslip',[$p,$line]))
    ->assertOk()->assertSee('Transport allowance')->assertSee('Reviewed withholding');
  $this->actingAs($u)->post(route('hr.payroll.components.store',[$p,$line]),[
   ...$allowance,'submission_token'=>(string)Str::uuid(),
  ])->assertSessionHasErrors('payroll_line_id');
 }
}
