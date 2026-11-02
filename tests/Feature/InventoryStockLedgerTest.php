<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\InventoryStockMovement;
use App\Models\Currency;
use App\Models\User;
use App\Services\InventoryCostService;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class InventoryStockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_receipt_and_issue_preserve_exact_balance(): void
    {
        $material = InventoryMaterial::create([
            'sku' => 'RM-001', 'name' => 'Flour', 'unit' => 'kg', 'is_packaging' => false,
        ]);
        $stock = app(InventoryStockService::class);
        $stock->post($material, $this->entry('opening', 'in', '10.000001', 'opening', $material->id));
        $stock->post($material, $this->entry('receipt', 'in', '2.000002', 'receipt_line', 1));
        $stock->post($material, $this->entry('issue', 'out', '3.000003', 'issue_line', 1));

        $this->assertSame('9.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 3);
    }

    public function test_duplicate_opening_and_negative_stock_are_rejected(): void
    {
        $material = InventoryMaterial::create([
            'sku' => 'RM-002', 'name' => 'Sugar', 'unit' => 'kg',
        ]);
        $stock = app(InventoryStockService::class);
        $stock->post($material, $this->entry('opening', 'in', '1', 'opening', $material->id));

        try {
            $stock->post($material, $this->entry('opening', 'in', '5', 'opening', $material->id));
            $this->fail('Duplicate opening was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        try {
            $stock->post($material, $this->entry('issue', 'out', '2', 'issue_line', 3));
            $this->fail('Over-issue was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame('1.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 1);
    }

    public function test_posted_ledger_entries_cannot_be_deleted(): void
    {
        $material = InventoryMaterial::create([
            'sku' => 'RM-003', 'name' => 'Bag', 'unit' => 'pcs', 'is_packaging' => true,
        ]);
        $entry = app(InventoryStockService::class)->post(
            $material, $this->entry('opening', 'in', '25', 'opening', $material->id)
        );

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_opening_inventory_can_capture_currency_specific_fifo_cost(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true, 'is_base' => false]);
        $material = InventoryMaterial::create(['sku' => 'RM-COST-01', 'name' => 'Flour', 'unit' => 'kg']);
        $movement = app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '5.000000',
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);
        app(InventoryCostService::class)->createFromInboundMovement($movement, '2.500000', $currency->id, $user->id);

        app(InventoryStockService::class)->post($material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '2.000000',
            'reference_type' => 'cost-test-opening-issue', 'reference_id' => $material->id,
            'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('inventory_cost_consumptions', [
            'quantity' => '2.000000', 'amount' => '5.0000000000', 'currency_code' => 'USD', 'is_valued' => true,
        ]);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'source_type' => 'inventory_stock_movement', 'source_id' => $movement->id,
            'remaining_quantity' => '3.000000', 'original_cost' => '12.5000000000',
        ]);
    }

    private function entry(string $type, string $direction, string $quantity, string $reference, int $id): array
    {
        return [
            'type' => $type, 'direction' => $direction, 'quantity' => $quantity,
            'reference_type' => $reference, 'reference_id' => $id,
            'occurred_on' => '2026-11-01',
        ];
    }
}
