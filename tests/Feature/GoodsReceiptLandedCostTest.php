<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLandedCost;
use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\GoodsReceiptLandedCostService;
use App\Services\InventoryStockService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GoodsReceiptLandedCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_unsupported_component_before_accessing_receipt(): void
    {
        $this->expectException(ValidationException::class);
        app(GoodsReceiptLandedCostService::class)->record(1, 'unknown', '10.00', 1);
    }

    public function test_rejects_fractional_cent_amount_before_accessing_receipt(): void
    {
        $this->expectException(ValidationException::class);
        app(GoodsReceiptLandedCostService::class)->record(1, 'freight', '10.001', 1);
    }

    public function test_rejects_missing_receipt(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(GoodsReceiptLandedCostService::class)->record(999999, 'freight', '10.00', 1);
    }

    public function test_snapshot_models_reject_modification(): void
    {
        $cost = new GoodsReceiptLandedCost(['component' => 'freight']);
        $cost->forceFill(['id' => 1]);
        $cost->exists = true;
        $this->expectException(\LogicException::class);
        $cost->delete();
    }

    public function test_allocation_uses_frozen_line_values_and_reconciles_exactly(): void
    {
        [$user, $order, $receipt] = $this->postedReceiptWithTwoLines();

        $cost = app(GoodsReceiptLandedCostService::class)->record($receipt->id, 'freight', '20.00', $user->id);

        $allocations = $cost->allocations->sortBy('goods_receipt_line_id')->values();
        $formattedAllocations = $allocations->map(
            fn ($allocation) => (string) BigDecimal::of((string) $allocation->amount)->toScale(2)
        )->all();
        $this->assertSame(['5.00', '15.00'], $formattedAllocations);
        $allocationTotal = BigDecimal::of((string) $allocations[0]->amount)
            ->plus((string) $allocations[1]->amount)
            ->toScale(2);
        $this->assertSame('20.00', (string) $allocationTotal);
        $this->assertSame('AFN', $cost->currency_code);
        $this->assertSame('received', $order->fresh()->status);
        $this->assertDatabaseCount('inventory_stock_movements', 2);
    }

    public function test_same_landed_cost_component_cannot_be_posted_twice(): void
    {
        [$user, , $receipt] = $this->postedReceiptWithTwoLines();
        $service = app(GoodsReceiptLandedCostService::class);
        $service->record($receipt->id, 'customs', '8.00', $user->id);

        try {
            $service->record($receipt->id, 'customs', '8.00', $user->id);
            $this->fail('A duplicate landed-cost component should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('component', $exception->errors());
        }

        $this->assertDatabaseCount('goods_receipt_landed_costs', 1);
        $this->assertDatabaseCount('goods_receipt_landed_cost_allocations', 2);
    }

    public function test_grn_landed_cost_is_carried_into_fifo_issue_cost(): void
    {
        [$user, , $receipt] = $this->postedReceiptWithTwoLines();
        app(GoodsReceiptLandedCostService::class)->record($receipt->id, 'freight', '20.00', $user->id);
        $line = $receipt->lines()->orderBy('id')->first();
        $material = $line->material;

        app(InventoryStockService::class)->post($material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '0.500000',
            'reference_type' => 'cost-test-issue', 'reference_id' => $line->id,
            'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('inventory_cost_consumptions', [
            'quantity' => '0.500000', 'amount' => '52.5000000000', 'currency_code' => 'AFN', 'is_valued' => true,
        ]);
        $this->assertSame('0.500000', $material->fresh()->on_hand_quantity);
        $valuation = app(\App\Services\InventoryCostService::class)->valuationRows('AFN');
        $this->assertSame('52.5000000000', $valuation[0]['value']);
        $this->assertSame('0.5', $valuation[0]['quantity_display']);
    }

    public function test_landed_cost_is_rejected_after_receipt_material_was_issued(): void
    {
        [$user, , $receipt] = $this->postedReceiptWithTwoLines();
        $line = $receipt->lines()->orderBy('id')->first();
        app(InventoryStockService::class)->post($line->material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '0.500000',
            'reference_type' => 'cost-test-issue', 'reference_id' => $line->id,
            'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);

        try {
            app(GoodsReceiptLandedCostService::class)->record($receipt->id, 'freight', '20.00', $user->id);
            $this->fail('Landed cost after issue should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }
    }

    public function test_landed_cost_route_requires_inventory_update_permission(): void
    {
        [$user, $order, $receipt] = $this->postedReceiptWithTwoLines();
        Permission::findOrCreate('inventory.update', 'web');

        $this->actingAs($user)
            ->post(route('purchases.orders.receipts.landed-costs.store', [$order, $receipt]), [
                'component' => 'freight', 'amount' => '10.00',
            ])
            ->assertForbidden();

        $user->givePermissionTo('inventory.update');
        $this->actingAs($user)
            ->post(route('purchases.orders.receipts.landed-costs.store', [$order, $receipt]), [
                'component' => 'freight', 'amount' => '10.00',
            ])
            ->assertRedirect(route('purchases.orders.show', $order));

        $this->assertDatabaseCount('goods_receipt_landed_costs', 1);
    }

    private function postedReceiptWithTwoLines(): array
    {
        $user = User::factory()->create();
        $supplier = TradeParty::create([
            'type' => 'supplier', 'name' => 'Synthetic GRN Supplier', 'code' => 'SUP-LC-TEST',
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $firstMaterial = InventoryMaterial::create(['sku' => 'LC-TEST-1', 'name' => 'Synthetic Material 1', 'unit' => 'kg']);
        $secondMaterial = InventoryMaterial::create(['sku' => 'LC-TEST-2', 'name' => 'Synthetic Material 2', 'unit' => 'kg']);
        $order = PurchaseOrder::create([
            'number' => 'PO-LC-TEST', 'supplier_id' => $supplier->id, 'currency_id' => $currency->id,
            'source' => 'local', 'order_date' => '2026-11-02', 'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        $firstItem = $order->items()->create([
            'description' => 'Synthetic Material 1', 'quantity' => '1.0000', 'unit_price' => '100.0000',
            'inventory_material_id' => $firstMaterial->id,
        ]);
        $secondItem = $order->items()->create([
            'description' => 'Synthetic Material 2', 'quantity' => '1.0000', 'unit_price' => '300.0000',
            'inventory_material_id' => $secondMaterial->id,
        ]);
        $receipt = app(GoodsReceiptService::class)->post($order, [
            'receipt_token' => (string) Str::uuid(),
            'received_on' => '2026-11-02',
            'quantities' => [$firstItem->id => '1.000000', $secondItem->id => '1.000000'],
        ], $user->id);

        return [$user, $order, $receipt];
    }
}
