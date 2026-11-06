<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\FinishedGood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OperationalReportCenterTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        Permission::findOrCreate('reports.view', 'web');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.view');

        return $viewer;
    }

    public function test_all_report_navigation_entries_display_actual_registers_or_finance_statements(): void
    {
        $user = $this->viewer();

        foreach (array_keys(config('erp_pages.reports.pages')) as $page) {
            $this->actingAs($user)->get(route('workspace.page', ['reports', $page]))
                ->assertOk();
        }

        $this->actingAs($user)
            ->get(route('workspace.page', ['reports', 'inventory']))
            ->assertSee('Current company-wide stock snapshot')
            ->assertDontSee('Structure ready');
    }

    public function test_report_view_and_csv_export_require_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('workspace.page', ['reports', 'inventory']))->assertForbidden();
        $this->actingAs($user)->get(route('workspace.page', ['reports', 'inventory'], false).'?export=csv')->assertForbidden();
        $this->actingAs($user)->get(route('workspace.page', ['reports', 'unknown']))->assertNotFound();
    }

    public function test_inventory_report_uses_live_quantities_and_csv_escapes_spreadsheet_formulas(): void
    {
        $user = $this->viewer();
        InventoryMaterial::create(['sku' => 'SAFE-1', 'name' => '=HYPERLINK("https://invalid.example")', 'unit' => 'kg']);

        $this->actingAs($user)->get(route('workspace.page', ['reports', 'inventory']))
            ->assertOk()->assertSee('SAFE-1')->assertSee('0.000000');

        $response = $this->actingAs($user)->get(route('workspace.page', ['reports', 'inventory']).'?export=csv');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('SAFE-1', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        $this->actingAs($user)->get(route('workspace.page', ['reports', 'inventory']).'?search=nomatch')
            ->assertSee('No records match these filters.')
            ->assertDontSee('SAFE-1');
    }

    public function test_batch_traceability_displays_actual_qc_and_dispatch_figures(): void
    {
        $user = $this->viewer();
        $recipe = ProductionRecipe::create([
            'name' => 'Synthetic package', 'method' => 'powder_blending',
            'package_weight_kg' => '5', 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-RPT-1', 'production_recipe_id' => $recipe->id,
            'package_count' => 7, 'status' => 'completed',
        ]);
        $good = FinishedGood::create([
            'sku' => 'FG-RPT-1', 'name' => 'Synthetic bag',
            'package_unit' => 'bag', 'package_weight_kg' => '5',
        ]);
        ProductionBatch::create([
            'production_order_id' => $order->id, 'finished_good_id' => $good->id,
            'batch_number' => 'BATCH-RPT-1', 'reported_packages' => 7,
        ])->forceFill([
            'accepted_packages' => 6, 'rejected_packages' => 1, 'status' => 'released',
        ])->save();

        $this->actingAs($user)->get(route('workspace.page', ['reports', 'traceability']))
            ->assertOk()->assertSee('BATCH-RPT-1')
            ->assertSee('FG-RPT-1')->assertSee('PRD-RPT-1')
            ->assertSee('Gross dispatched');
    }

    public function test_invalid_date_filters_are_rejected(): void
    {
        $user = $this->viewer();
        $this->actingAs($user)->get(route('workspace.page', ['reports', 'sales']).'?from=2026-11-30&to=2026-11-01')
            ->assertSessionHasErrors('to');
    }
}
