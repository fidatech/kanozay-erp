<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinishedGood;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMaterial;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\InventoryStockService;
use App\Services\ManufacturingCostAccountingService;
use App\Services\MatchedSupplierInvoiceService;
use Brick\Math\BigDecimal;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\CapitalizesProductionBatches;
use Tests\TestCase;

/**
 * Scenarios A and B joined (D05): purchase receipt -> supplier invoice -> FIFO material issue
 * -> production batch -> laboratory QC -> warehouse release -> the real
 * ManufacturingCostAccountingService::capitalize(), with raw material, WIP and finished
 * goods reconciled between the stock ledgers and the General Ledger.
 *
 * Reservation and the material-issue HTTP routes are covered by ProductionMaterialIssueTest;
 * here the issue is posted through InventoryStockService against the order line.
 */
class ProcurementToFinishedGoodsCostChainTest extends TestCase
{
    use CapitalizesProductionBatches;
    use RefreshDatabase;

    private User $buyer;
    private User $operator;
    private User $laboratory;
    private User $warehouse;
    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manufacturing.update', 'laboratory.create', 'laboratory.update', 'inventory.update', 'inventory.create', 'inventory.view', 'administration.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->buyer = User::factory()->create();
        $this->buyer->givePermissionTo(['inventory.update', 'administration.view']);
        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['manufacturing.update', 'administration.view']);
        $this->laboratory = User::factory()->create();
        $this->laboratory->givePermissionTo(['laboratory.create', 'laboratory.update', 'administration.view']);
        $this->warehouse = User::factory()->create();
        $this->warehouse->givePermissionTo(['inventory.update', 'inventory.create', 'inventory.view', 'administration.view']);
        $this->finance = User::factory()->create();
    }

    /**
     * Receives 10 kg at 20.0000 through the GRN route and, unless told otherwise, posts the
     * matching supplier invoice into the approved raw-material inventory ledger.
     *
     * @return array{currency:Currency,policy:object,ledgers:array,material:InventoryMaterial,receipt:GoodsReceipt,line:GoodsReceiptLine}
     */
    private function receivedMaterial(bool $withSupplierInvoice = true): array
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        ['policy' => $policy, 'ledgers' => $ledgers] = $this->approvedManufacturingPolicyForTest($currency);
        $supplier = TradeParty::create(['type' => 'supplier', 'name' => 'Synthetic spice supplier', 'is_active' => true, 'created_by' => $this->buyer->id]);
        $supplier->currencies()->attach($currency->id);
        app(EntityAccountProvisioner::class)->provision('supplier', $supplier->id, $supplier->name, $currency->id);
        $material = InventoryMaterial::create(['sku' => 'CHAIN-RAW-01', 'name' => 'Synthetic chilli powder', 'unit' => 'kg']);
        $order = PurchaseOrder::create([
            'number' => 'PO-CHAIN-1', 'supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'source' => 'local',
            'order_date' => today()->subDays(5)->toDateString(), 'status' => 'confirmed', 'created_by' => $this->buyer->id,
        ]);
        $item = $order->items()->create(['description' => 'Chilli powder', 'quantity' => '10.0000', 'unit_price' => '20.0000', 'inventory_material_id' => $material->id]);

        $this->actingAs($this->buyer)->post(route('purchases.orders.receipts.store', $order), [
            'receipt_token' => (string) Str::uuid(), 'received_on' => today()->subDays(3)->toDateString(),
            'supplier_reference' => 'SYN-DN-1', 'quantities' => [$item->id => '10.000000'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::firstOrFail();
        $line = GoodsReceiptLine::firstOrFail();
        $this->assertSame('10.000000', $material->fresh()->on_hand_quantity);

        if ($withSupplierInvoice) {
            $invoice = app(MatchedSupplierInvoiceService::class)->post($receipt, [
                'submission_token' => (string) Str::uuid(), 'supplier_invoice_number' => 'SYN-SUP-INV-1',
                'invoice_date' => today()->subDays(2)->toDateString(), 'due_date' => today()->addDays(20)->toDateString(),
                'inventory_ledger_id' => (int) $policy->material_inventory_ledger_id,
                'invoice_total' => '200', 'quantities' => [$line->id => '10'],
            ], $this->finance->id);
            $this->assertSame('posted', $invoice->status);
            // Raw-material inventory in the GL equals the stock ledger value: 10 kg x 20.
            $this->assertSame('200.0000', $this->ledgerBalanceForTest((int) $policy->material_inventory_ledger_id, $currency->id));
        }

        return compact('currency', 'policy', 'ledgers', 'material', 'receipt', 'line');
    }

    /** Issues 5 kg to a production order, reports 17 packages, QC accepts 15, warehouse releases. */
    private function releasedBatchConsuming(InventoryMaterial $material): array
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Synthetic chilli sachets', 'product_code' => 'FG-CHAIN-1', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create(['number' => 'PRD-CHAIN-1', 'production_recipe_id' => $recipe->id, 'package_count' => 20, 'status' => 'issued']);
        $good = FinishedGood::create(['sku' => 'FG-CHAIN-1', 'name' => 'Synthetic chilli sachets', 'package_unit' => 'bag', 'package_weight_kg' => '5.000']);
        $materialLine = $order->materials()->create([
            'line_number' => 1, 'material_name' => $material->name, 'unit' => 'kg',
            'quantity_per_package' => '0.250000', 'required_quantity' => '5.000000',
            'issued_quantity' => '5.000000', 'inventory_material_id' => $material->id,
        ]);
        app(InventoryStockService::class)->post($material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '5.000000',
            'reference_type' => 'production_order_material', 'reference_id' => $materialLine->id,
            'occurred_on' => today()->subDay()->toDateString(), 'created_by' => $this->operator->id,
        ]);

        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $good->id, 'reported_packages' => 17,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $batch = ProductionBatch::firstOrFail();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), [
            'test_name' => 'Moisture', 'specification' => 'Within limit', 'observation' => 'Acceptable', 'result' => 'pass',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), [
            'decision' => 'approve', 'rejected_packages' => 2, 'qc_notes' => 'Two damaged bags.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->warehouse)->post(route('production.orders.batches.release', [$order, $batch]), [
            'occurred_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return [$order, $good, $batch->fresh()];
    }

    private function approval(string $reference = 'SYN-BATCH-COST-1'): array
    {
        return [
            'accounting_date' => today()->toDateString(), 'approval_reference' => $reference,
            'reconciliation_notes' => 'Synthetic FIFO issues agreed to the posted supplier invoice and receipt.',
        ];
    }

    public function test_purchase_receipt_to_capitalized_finished_goods_reconciles_stock_wip_and_ledger(): void
    {
        ['currency' => $currency, 'policy' => $policy, 'material' => $material] = $this->receivedMaterial();
        [, $good, $batch] = $this->releasedBatchConsuming($material);

        // Physical position before costing: 5 kg left, 15 accepted packages in stock.
        $this->assertSame('5.000000', $material->fresh()->on_hand_quantity);
        $this->assertSame('released', $batch->status);
        $this->assertSame(15, (int) $batch->accepted_packages);
        $this->assertEquals(15, $good->fresh()->on_hand_packages);
        $frozen = $batch->costs()->firstOrFail();
        $this->assertTrue(BigDecimal::of((string) $frozen->material_cost)->isEqualTo('100'));

        $service = app(ManufacturingCostAccountingService::class);
        $posting = $service->capitalize($batch->id, $this->approval(), $this->finance->id);

        // Compared numerically: SQLite and MySQL format stored decimals differently.
        $this->assertTrue(BigDecimal::of((string) $posting->material_amount)->isEqualTo('100'));
        $this->assertTrue(BigDecimal::of((string) $posting->overhead_amount)->isZero());
        $this->assertTrue(BigDecimal::of((string) $posting->total_amount)->isEqualTo('100'));
        $this->assertSame(15, (int) $posting->accepted_packages);

        // General Ledger after capitalization.
        $raw = (int) $policy->material_inventory_ledger_id;
        $wip = (int) $policy->work_in_progress_ledger_id;
        $finished = (int) $policy->finished_inventory_ledger_id;
        $this->assertSame('100.0000', $this->ledgerBalanceForTest($raw, $currency->id));
        $this->assertSame('0.0000', $this->ledgerBalanceForTest($wip, $currency->id));
        $this->assertSame('100.0000', $this->ledgerBalanceForTest($finished, $currency->id));

        // Inventory-to-GL reconciliation: remaining FIFO layer value equals the raw-material ledger.
        $layerValue = BigDecimal::zero();
        foreach (DB::table('inventory_cost_layers')->where('inventory_material_id', $material->id)->get() as $layer) {
            $layerValue = $layerValue->plus(
                BigDecimal::of((string) $layer->original_cost)
                    ->multipliedBy((string) $layer->remaining_quantity)
                    ->dividedBy((string) $layer->original_quantity, 10, \Brick\Math\RoundingMode::HalfUp)
            );
        }
        $this->assertTrue($layerValue->isEqualTo($this->ledgerBalanceForTest($raw, $currency->id)));

        // Both journals are posted and balanced; WIP was debited and cleared by the same amount.
        foreach ([$posting->issue_journal_id, $posting->capitalization_journal_id] as $journalId) {
            $this->assertSame('posted', DB::table('journal_entries')->where('id', $journalId)->value('status'));
            $lines = DB::table('journal_lines')->where('journal_entry_id', $journalId)->get();
            $debit = BigDecimal::zero();
            $credit = BigDecimal::zero();
            foreach ($lines as $line) {
                $debit = $debit->plus((string) $line->debit);
                $credit = $credit->plus((string) $line->credit);
            }
            $this->assertTrue($debit->isEqualTo('100') && $credit->isEqualTo('100'));
        }

        // Replaying the same approval returns the same posting; a different one is refused.
        $journals = DB::table('journal_entries')->count();
        $replayed = $service->capitalize($batch->id, $this->approval(), $this->finance->id);
        $this->assertSame((int) $posting->id, (int) $replayed->id);
        try {
            $service->capitalize($batch->id, $this->approval('SYN-BATCH-COST-2'), $this->finance->id);
            $this->fail('A capitalized batch must not be capitalized again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manufacturing_cost', $exception->errors());
        }
        $this->assertDatabaseCount('production_batch_cost_postings', 1);
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame('100.0000', $this->ledgerBalanceForTest($finished, $currency->id));
    }

    public function test_batch_cannot_be_capitalized_before_the_supplier_invoice_puts_the_material_in_the_ledger(): void
    {
        ['currency' => $currency, 'policy' => $policy, 'material' => $material] = $this->receivedMaterial(withSupplierInvoice: false);
        [, , $batch] = $this->releasedBatchConsuming($material);
        $journals = DB::table('journal_entries')->count();

        try {
            app(ManufacturingCostAccountingService::class)->capitalize($batch->id, $this->approval(), $this->finance->id);
            $this->fail('Material that never reached the inventory ledger must not be moved to WIP.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manufacturing_cost', $exception->errors());
        }
        $this->assertDatabaseCount('production_batch_cost_postings', 0);
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame('0.0000', $this->ledgerBalanceForTest((int) $policy->finished_inventory_ledger_id, $currency->id));
    }

    public function test_batch_that_is_not_released_by_the_warehouse_cannot_be_capitalized(): void
    {
        ['material' => $material] = $this->receivedMaterial();
        $recipe = ProductionRecipe::create([
            'name' => 'Synthetic unreleased', 'product_code' => 'FG-CHAIN-2', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create(['number' => 'PRD-CHAIN-2', 'production_recipe_id' => $recipe->id, 'package_count' => 20, 'status' => 'issued']);
        $good = FinishedGood::create(['sku' => 'FG-CHAIN-2', 'name' => 'Synthetic unreleased', 'package_unit' => 'bag', 'package_weight_kg' => '5.000']);
        $line = $order->materials()->create([
            'line_number' => 1, 'material_name' => $material->name, 'unit' => 'kg', 'quantity_per_package' => '0.250000',
            'required_quantity' => '5.000000', 'issued_quantity' => '5.000000', 'inventory_material_id' => $material->id,
        ]);
        app(InventoryStockService::class)->post($material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '5.000000', 'reference_type' => 'production_order_material',
            'reference_id' => $line->id, 'occurred_on' => today()->subDay()->toDateString(), 'created_by' => $this->operator->id,
        ]);
        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $good->id, 'reported_packages' => 10,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $batch = ProductionBatch::firstOrFail();

        try {
            app(ManufacturingCostAccountingService::class)->capitalize($batch->id, $this->approval(), $this->finance->id);
            $this->fail('A batch awaiting QC must not be capitalized.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manufacturing_cost', $exception->errors());
        }
        $this->assertDatabaseCount('production_batch_cost_postings', 0);
    }
}
