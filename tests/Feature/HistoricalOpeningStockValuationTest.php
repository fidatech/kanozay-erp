<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\CompanyWarehouse;
use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\InventoryStockService;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\CapitalizesProductionBatches;
use Tests\TestCase;

/**
 * D02/D03: an opening stock lot entered without a cost is valued later through an
 * independently reviewed historical correction case. The physical receipt is not touched,
 * the inventory asset and opening equity are recognized once, and later FIFO issues use
 * the recognized cost.
 */
class HistoricalOpeningStockValuationTest extends TestCase
{
    use CapitalizesProductionBatches; // policy and ledger balance helpers
    use RefreshDatabase;

    private const TEXT = 'Synthetic opening count sheet agreed to the signed supplier statement.';

    public function test_unvalued_opening_lot_is_valued_once_through_a_reviewed_case_and_then_drives_fifo_cost(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        foreach (['finance.view', 'finance.update', 'inventory.view', 'inventory.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        [$stockkeeper, $requester, $reviewer, $poster] = User::factory()->count(4)->create()->all();
        $stockkeeper->givePermissionTo(['inventory.view', 'inventory.update', 'finance.update']);
        foreach ([$requester, $reviewer, $poster] as $user) {
            $user->givePermissionTo(['finance.view', 'finance.update']);
        }
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        ['policy' => $policy] = $this->approvedManufacturingPolicyForTest($currency);
        $inventoryLedger = (int) $policy->material_inventory_ledger_id;
        $equity = LedgerAccount::where('type', 'equity')->where('is_group', false)->where('is_active', true)
            ->whereNotIn('id', DB::table('entity_ledger_accounts')->pluck('ledger_account_id'))->orderBy('code')->firstOrFail();

        // Physical opening stock of 10 kg entered with no cost.
        $material = InventoryMaterial::create(['sku' => 'OPEN-RAW-01', 'name' => 'Synthetic turmeric', 'unit' => 'kg']);
        $stock = app(InventoryStockService::class);
        $stock->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '10.000000', 'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => today()->subDays(10)->toDateString(), 'created_by' => $stockkeeper->id,
        ]);
        $layer = DB::table('inventory_cost_layers')->where('inventory_material_id', $material->id)->first();
        $this->assertNull($layer->original_cost);
        $this->assertNull($layer->currency_id);

        // Requester files the case; duplicates and self-review are refused.
        $case = ['case_type' => 'opening_fifo_unvalued', 'source_id' => $layer->id, 'identified_discrepancy' => self::TEXT,
            'source_evidence' => self::TEXT, 'confirm_original_source' => '1'];
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertSessionHasErrors('historical_correction');
        $this->assertDatabaseCount('finance_historical_correction_cases', 1);
        $caseId = (int) DB::table('finance_historical_correction_cases')->value('id');
        $review = ['decision' => 'approve', 'approval_reference' => 'SYN-HFC-OK', 'review_notes' => self::TEXT, 'confirm_independent_review' => '1'];
        $posting = [
            'currency_id' => $currency->id, 'opening_equity_ledger_id' => $equity->id, 'original_cost' => '250.00',
            'posting_date' => today()->toDateString(), 'evidence_reference' => 'SYN-OPEN-EVID',
            'reconciliation_notes' => self::TEXT, 'confirm_no_prior_gl' => '1', 'confirm_physical_source' => '1',
        ];
        $post = fn (User $user) => $this->actingAs($user)->post(route('finance.historical-corrections.opening-post', $caseId), $posting);

        $post($poster)->assertSessionHasErrors('opening_stock'); // not approved yet
        $this->actingAs($requester)->post(route('finance.historical-corrections.review', $caseId), $review)->assertSessionHasErrors('historical_correction');
        $this->actingAs($reviewer)->post(route('finance.historical-corrections.review', $caseId), $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', DB::table('finance_historical_correction_cases')->where('id', $caseId)->value('status'));

        // Not until the lot is physically assigned to a warehouse.
        $post($poster)->assertSessionHasErrors('opening_stock');
        $branch = CompanyBranch::create(['code' => 'MAIN', 'name' => 'Main branch', 'is_active' => true]);
        $warehouse = CompanyWarehouse::create(['company_branch_id' => $branch->id, 'code' => 'WH-OPEN', 'name' => 'Synthetic raw store', 'is_active' => true]);
        $this->actingAs($stockkeeper)->post(route('inventory.warehouse-stock.store'), [
            'type' => 'initial_allocation', 'inventory_material_id' => $material->id, 'destination_warehouse_id' => $warehouse->id,
            'source_warehouse_id' => null, 'quantity' => '10', 'idempotency_key' => (string) Str::uuid(),
            'occurred_on' => today()->subDays(9)->toDateString(), 'reason' => 'Synthetic physical stock allocation',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Not by the requester, and not by the person who entered the physical stock.
        $post($requester)->assertSessionHasErrors('opening_stock');
        $post($stockkeeper)->assertSessionHasErrors('opening_stock');
        $this->assertDatabaseCount('journal_entries', 0);

        $post($poster)->assertRedirect()->assertSessionHasNoErrors();

        // Asset and equity recognized once; physical records unchanged.
        $this->assertSame('250.0000', $this->ledgerBalanceForTest($inventoryLedger, $currency->id));
        $this->assertSame('-250.0000', $this->ledgerBalanceForTest($equity->id, $currency->id));
        $valued = DB::table('inventory_cost_layers')->where('id', $layer->id)->first();
        $this->assertTrue(BigDecimal::of((string) $valued->original_cost)->isEqualTo('250'));
        $this->assertSame($currency->id, (int) $valued->currency_id);
        $this->assertTrue(BigDecimal::of((string) $valued->remaining_quantity)->isEqualTo('10'));
        $this->assertSame('10.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 1);
        $this->assertDatabaseCount('finance_opening_fifo_valuations', 1);
        $resolved = DB::table('finance_historical_correction_cases')->where('id', $caseId)->first();
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame($poster->id, (int) $resolved->executed_by);

        // A second posting, or a new case for the same lot, cannot value it again.
        $post($poster)->assertSessionHasErrors('opening_stock');
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertRedirect();
        $secondCase = (int) DB::table('finance_historical_correction_cases')->max('id');
        if ($secondCase !== $caseId) {
            $this->actingAs($reviewer)->post(route('finance.historical-corrections.review', $secondCase), $review);
            $this->actingAs($poster)->post(route('finance.historical-corrections.opening-post', $secondCase), $posting)->assertSessionHasErrors('opening_stock');
        }
        $this->assertDatabaseCount('finance_opening_fifo_valuations', 1);
        $this->assertSame('250.0000', $this->ledgerBalanceForTest($inventoryLedger, $currency->id));

        // Later FIFO issues carry the recognized cost: 4 kg of 10 kg at 250 = 100.
        $movement = $stock->post($material->fresh(), [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '4.000000', 'reference_type' => 'manual_issue', 'reference_id' => $material->id,
            'occurred_on' => today()->toDateString(), 'created_by' => $stockkeeper->id,
        ]);
        $consumed = DB::table('inventory_cost_consumptions')->where('inventory_cost_layer_id', $layer->id)->get();
        $this->assertCount(1, $consumed);
        $this->assertTrue((bool) $consumed[0]->is_valued);
        $this->assertSame($currency->id, (int) $consumed[0]->currency_id);
        $this->assertTrue(BigDecimal::of((string) $consumed[0]->amount)->isEqualTo('100'));

        // Read access and the audit trail.
        $this->actingAs($reviewer)->get(route('finance.historical-corrections.show', $caseId))->assertOk()->assertSee($resolved->case_number);
        $this->assertGreaterThanOrEqual(3, DB::table('finance_historical_correction_events')->where('finance_historical_correction_case_id', $caseId)->count());
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('finance.historical-corrections.show', $caseId))->assertForbidden();
        $this->actingAs($outsider)->post(route('finance.historical-corrections.store'), $case)->assertForbidden();
    }

    public function test_case_can_be_rejected_or_withdrawn_without_any_accounting_effect(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        foreach (['finance.view', 'finance.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        [$stockkeeper, $requester, $reviewer] = User::factory()->count(3)->create()->all();
        foreach ([$requester, $reviewer] as $user) {
            $user->givePermissionTo(['finance.view', 'finance.update']);
        }
        $material = InventoryMaterial::create(['sku' => 'OPEN-RAW-02', 'name' => 'Synthetic coriander', 'unit' => 'kg']);
        app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '3.000000', 'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => today()->subDays(4)->toDateString(), 'created_by' => $stockkeeper->id,
        ]);
        $layerId = (int) DB::table('inventory_cost_layers')->value('id');
        $case = ['case_type' => 'opening_fifo_unvalued', 'source_id' => $layerId, 'identified_discrepancy' => self::TEXT,
            'source_evidence' => self::TEXT, 'confirm_original_source' => '1'];
        $withdrawal = ['reason' => self::TEXT, 'confirm_withdrawal' => '1'];

        // Withdrawn by the requester only.
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertRedirect()->assertSessionHasNoErrors();
        $first = (int) DB::table('finance_historical_correction_cases')->max('id');
        $this->actingAs($reviewer)->post(route('finance.historical-corrections.withdraw', $first), $withdrawal)->assertSessionHasErrors('historical_correction');
        $this->actingAs($requester)->post(route('finance.historical-corrections.withdraw', $first), $withdrawal)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', DB::table('finance_historical_correction_cases')->where('id', $first)->value('status'));

        // The source is free again; a rejected case also frees it.
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertRedirect()->assertSessionHasNoErrors();
        $second = (int) DB::table('finance_historical_correction_cases')->max('id');
        $this->assertNotSame($first, $second);
        $this->actingAs($reviewer)->post(route('finance.historical-corrections.review', $second), [
            'decision' => 'reject', 'approval_reference' => 'SYN-HFC-NO', 'review_notes' => self::TEXT, 'confirm_independent_review' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', DB::table('finance_historical_correction_cases')->where('id', $second)->value('status'));
        $this->actingAs($requester)->post(route('finance.historical-corrections.store'), $case)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('finance_historical_correction_cases', 3);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertNull(DB::table('inventory_cost_layers')->where('id', $layerId)->value('original_cost'));
        $this->actingAs($reviewer)->get(route('finance.historical-corrections.index'))->assertOk();
    }
}
