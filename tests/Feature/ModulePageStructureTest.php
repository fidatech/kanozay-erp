<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModulePageStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_scaffolded_page_is_accessible_to_authorized_users(): void
    {
        $user=User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        Permission::findOrCreate('hr.view','web');
        $user->givePermissionTo(['administration.view', 'hr.view']);
        foreach(config('erp_pages') as $module=>$data){
            $this->actingAs($user)->get(route('workspace.page',[$module,'overview']))
                ->assertOk()->assertSee($data['label']);
            foreach($data['pages'] as $page=>$label){
                $expectedContent = match (true) {
                    $page === 'overview' => 'Daily operations',
                    $module === 'hr' && $page === 'employees' => 'Employee number',
                    $module === 'hr' && $page === 'payroll' => 'Payroll periods',
                    $module === 'hr' && $page === 'attendance' => 'Attendance register',
                    $module === 'hr' && $page === 'leave' => 'Leave requests',
                    $module === 'hr' && $page === 'departments' => 'Departments',
                    $module === 'hr' && $page === 'advances' => 'Salary advances',
                    $module === 'documents' => 'Document',
                    $module === 'sales' && $page === 'delivery-notes' => 'Delivery Notes',
                    $module === 'crm' => 'register',
                    $module === 'inventory' && in_array($page,['transfers','warehouses'],true) => 'Warehouse stock allocation',
                    $module === 'inventory' && $page === 'counts' => 'Physical Stock Counts',
                    $module === 'assets' && $page === 'register' => 'Fixed asset register',
                    $module === 'assets' && $page === 'depreciation' => 'Fixed asset depreciation',
                    $module === 'assets' && in_array($page,['maintenance','schedules','service-history','transfers','disposals'],true) => 'Asset lifecycle',
                    $module === 'purchases' && $page === 'orders' => 'New Purchase Order',
                    $module === 'purchases' && $page === 'purchase-returns' => 'Purchase Returns',
                    $module === 'manufacturing' && $page === 'recipes' => 'Production Recipes',
                    $module === 'manufacturing' && $page === 'orders' => 'Production Orders',
                    $module === 'shareholders' && in_array($page, ['register', 'capital', 'statements'], true) => 'Shareholder Register',
                    $module === 'reports' && $page === 'profit-loss' => 'Profit and Loss',
                    $module === 'reports' && $page === 'balance-sheet' => 'Balance Sheet',
                    $module === 'reports' && $page === 'cash-flow' => 'Cash, Bank and Sarafi Movements',
                    $module === 'reports' => 'Report register',
                    default => 'Workflow pending',
                };
                $this->actingAs($user)->get(route('workspace.page',[$module,$page]))
                    ->assertOk()
                    ->assertSee($module === 'reports' && in_array($page, ['profit-loss', 'cash-flow'], true)
                        ? $expectedContent : ($page === 'overview' ? $data['label'] : $label))
                    ->assertSee($expectedContent);
            }
        }
    }

    public function test_additional_workflow_is_highlighted_in_its_module_sidebar(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        Permission::findOrCreate('hr.view', 'web');
        $user->givePermissionTo(['administration.view', 'hr.view']);
        foreach (config('erp_pages') as $module => $data) {
            $additional = array_diff_key($data['pages'], array_flip(config('erp_navigation.'.$module, [])));
            foreach ($additional as $slug => $label) {
                $response = $this->actingAs($user)->get(route('workspace.page', [$module, $slug]));
                $response->assertOk()->assertSee('aria-current="page"', false)
                    ->assertSee($label);
            }
        }
    }

    public function test_module_overviews_do_not_duplicate_audit_activity(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        foreach (array_keys(config('erp_pages')) as $module) {
            $this->actingAs($user)->get(route('workspace.page', [$module, 'overview']))
                ->assertOk()->assertDontSee('Recent system activity');
        }
        $this->actingAs($user)->get(route('admin.activity.index'))->assertOk();
    }

    public function test_sales_and_purchase_overviews_show_real_accounting_snapshots(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        $this->actingAs($user)->get(route('workspace.page', ['sales', 'overview']))
            ->assertOk()->assertSee('Sales accounting snapshot')->assertSee('Recent receivable documents')
            ->assertSee('No receivable documents recorded yet.');
        $this->actingAs($user)->get(route('workspace.page', ['purchases', 'overview']))
            ->assertOk()->assertSee('Purchase accounting snapshot')->assertSee('Recent payable documents')
            ->assertSee('No payable documents recorded yet.');
    }

    public function test_finance_and_administration_overviews_are_accessible(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo('administration.view');
        $this->actingAs($user)->get(route('finance.overview'))
            ->assertOk()->assertSee('Finance &amp; Accounting Overview', false);
        $this->actingAs($user)->get(route('admin.index'))
            ->assertOk()->assertSee('Administration Overview');
        $this->assertArrayNotHasKey('overview', config('erp_pages.reports.pages'));
    }

    public function test_unknown_pages_and_unauthorized_access_are_rejected(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->get(route('workspace.page',['purchases','orders']))->assertForbidden();
        Permission::findOrCreate('administration.view','web');
        $user->givePermissionTo('administration.view');
        $this->actingAs($user)->get(route('workspace.page',['unknown','orders']))->assertNotFound();
        $this->actingAs($user)->get(route('workspace.page',['purchases','unknown']))->assertNotFound();
    }
}
