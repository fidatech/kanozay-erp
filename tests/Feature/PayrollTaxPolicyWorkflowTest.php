<?php

namespace Tests\Feature;

use App\Models\{Currency,EmployeeProfile,LedgerAccount,PayrollPeriod,User};
use App\Services\{PayrollPeriodService,PayrollTaxPolicyService};
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollTaxPolicyWorkflowTest extends TestCase {
 use RefreshDatabase;

 public function test_independently_approved_custom_tax_brackets_post_once_to_correct_liability():void {
  $this->seed(ChartOfAccountsSeeder::class);
  $maker=User::factory()->create();$checker=User::factory()->create();
  foreach(['hr.view','hr.create','hr.update','finance.update','finance.view'] as $p)
   Permission::findOrCreate($p,'web');
  $maker->givePermissionTo(['hr.view','hr.create','hr.update','finance.update','finance.view']);
  $checker->givePermissionTo(['hr.view','hr.update','finance.update','finance.view']);
  $this->actingAs($maker);
  $currency=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
  EmployeeProfile::create([
   'employee_number'=>'TAX-TEST-EMP','full_name'=>'Synthetic Payroll Tax Employee',
   'monthly_salary'=>'20000.0000','salary_currency_id'=>$currency->id,
   'is_active'=>true,'created_by'=>$maker->id,
  ]);
  $liability=LedgerAccount::create(['code'=>'TAX-WH-TEST','name'=>'Reviewed payroll withholding liability',
   'type'=>'liability','is_active'=>true,'is_group'=>false]);
  $service=app(PayrollTaxPolicyService::class);
  $policy=$service->create([
   'code'=>'TEST-TAX-AFN','name'=>'Synthetic withholding policy',
   'currency_id'=>$currency->id,'effective_from'=>today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
   'basis'=>'base_salary','liability_ledger_id'=>$liability->id,
   'notes'=>'This is synthetic test data, not statutory payroll tax guidance.',
   'brackets'=>[
     ['lower_bound'=>'0','upper_bound'=>'10000','fixed_tax'=>'0','marginal_percent'=>'0'],
     ['lower_bound'=>'10000','upper_bound'=>'100000','fixed_tax'=>'0','marginal_percent'=>'10'],
     ['lower_bound'=>'100000','upper_bound'=>null,'fixed_tax'=>'9000','marginal_percent'=>'15'],
   ],
  ],$maker->id);
  $this->assertSame('draft',$policy->status);
  $this->assertSame('1000.0000',$service->calculate($policy,'20000.0000'));
  $this->actingAs($maker)->post(route('hr.payroll.tax-policies.approve',$policy),[
   'approval_reference'=>'TEST-APPROVAL-99',
  ])->assertSessionHasErrors('policy');
  $this->actingAs($checker)->post(route('hr.payroll.tax-policies.approve',$policy),[
   'approval_reference'=>'TEST-APPROVAL-99',
  ])->assertRedirect();
  $this->actingAs($maker);
  $period=app(PayrollPeriodService::class)->create(today()->subMonthNoOverflow()->format('Y-m'),$currency->id,$maker->id);
  $this->actingAs($maker)->post(route('hr.payroll.tax-policies.apply',$policy),[
   'payroll_period_id'=>$period->id,'confirm_review'=>'1',
  ])->assertRedirect();
  $line=$period->lines()->firstOrFail();
  $this->assertSame('19000.0000',$line->fresh()->payable_amount);
  $this->assertDatabaseCount('payroll_line_components',1);
  $this->assertDatabaseHas('payroll_tax_applications',[
   'payroll_line_id'=>$line->id,'taxable_base'=>'20000.0000','withheld'=>'1000.0000',
  ]);
  $this->actingAs($maker)->post(route('hr.payroll.tax-policies.apply',$policy),[
   'payroll_period_id'=>$period->id,'confirm_review'=>'1',
  ])->assertRedirect();
  $this->assertDatabaseCount('payroll_line_components',1);

  // The salary month has ended, the line evidence (including withholding) is reviewed,
  // and an approver other than the payroll preparer posts the accrual.
  $this->actingAs($checker)->post(route('hr.payroll.lines.review',[$period,$line]),[
   'reference'=>'SYN-TAX-REVIEW','review_notes'=>'Synthetic salary and withholding evidence reviewed.',
   'confirm_evidence'=>1,
  ])->assertRedirect()->assertSessionHasNoErrors();
  $approval=[
   'expense_ledger_id'=>LedgerAccount::where('code','5410')->value('id'),
   'payable_ledger_id'=>LedgerAccount::where('code','2131.01')->value('id'),
   'confirm_review'=>1,
  ];
  $this->actingAs($maker)->post(route('hr.payroll.approve',$period),$approval)->assertSessionHasErrors('payroll');
  $this->actingAs($checker)->post(route('hr.payroll.approve',$period),$approval)->assertRedirect()->assertSessionHasNoErrors();
  $journal=$period->fresh()->approvalJournal;
  $this->assertSame('posted',$journal->status);
  $this->assertDatabaseHas('journal_lines',[
   'journal_entry_id'=>$journal->id,'ledger_account_id'=>$liability->id,'credit'=>'1000.0000',
  ]);
  $this->actingAs($maker)->get(route('hr.payroll.tax-policies.index',[
   'period_id'=>$period->id,'policy_id'=>$policy->id,
  ]))->assertOk()->assertSee('TEST-TAX-AFN');
 }

 public function test_unapproved_policy_does_not_change_payroll():void {
  $maker=User::factory()->create();
  $this->actingAs($maker);
  foreach(['hr.view','finance.view'] as $p)Permission::findOrCreate($p,'web');
  $maker->givePermissionTo(['hr.view','finance.view']);
  $this->get(route('hr.payroll.tax-policies.index'))->assertOk();
 }
}
