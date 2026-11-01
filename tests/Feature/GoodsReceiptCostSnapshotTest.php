<?php

namespace Tests\Feature;

use App\Models\{Currency, GoodsReceipt, GoodsReceiptLine, InventoryMaterial, PurchaseOrder, TradeParty, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GoodsReceiptCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $currencyCode, string $price): array
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.update', 'web');
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo(['inventory.update', 'administration.view']);
        $supplier = TradeParty::create([
            'type' => 'supplier', 'name' => 'Cost Test Supplier',
            'code' => 'SUP-COST-'.Str::random(8), 'is_active' => true, 'created_by' => $user->id,
        ]);
        $currency = Currency::create([
            'code' => $currencyCode, 'name' => $currencyCode, 'is_active' => true, 'is_base' => true,
        ]);
        $material = InventoryMaterial::create([
            'sku' => 'COST-'.Str::random(8), 'name' => 'Costed Flour', 'unit' => 'kg',
        ]);
        $order = PurchaseOrder::create([
            'number' => 'PO-COST-'.Str::random(8), 'supplier_id' => $supplier->id,
            'currency_id' => $currency->id, 'source' => 'local',
            'order_date' => '2026-11-01', 'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        $item = $order->items()->create([
            'description' => 'Flour', 'quantity' => '10.0000',
            'unit_price' => $price, 'inventory_material_id' => $material->id,
        ]);

        return [$user, $order, $item, $material, $currency];
    }

    public function test_partial_receipt_freezes_exact_purchase_value_and_currency(): void
    {
        [$user, $order, $item, $material, $currency] = $this->makeOrder('AFN', '12.3456');
        $this->actingAs($user)->post(route('purchases.orders.receipts.store', $order), [
            'receipt_token' => (string) Str::uuid(),
            'received_on' => '2026-11-01',
            'quantities' => [$item->id => '3.125000'],
        ])->assertRedirect();

        $receipt = GoodsReceipt::firstOrFail();
        $line = GoodsReceiptLine::firstOrFail();
        $this->assertSame($currency->id, $receipt->cost_currency_id);
        $this->assertSame('AFN', $receipt->cost_currency_code);
        $this->assertSame('12.3456', $line->unit_price_snapshot);
        $this->assertSame('38.5800000000', $line->line_subtotal_snapshot);
        $this->assertSame('3.125000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 1);

        // A later source edit cannot rewrite the already posted cost history.
        $item->update(['unit_price' => '99.0000']);
        $this->assertSame('12.3456', $line->fresh()->unit_price_snapshot);
        $this->assertSame('38.5800000000', $line->fresh()->line_subtotal_snapshot);
        $this->actingAs($user)->get(route('purchases.orders.show', $order))
            ->assertOk()->assertSee('38.58 AFN');
    }

    public function test_tiny_fractional_receipt_value_remains_exact_in_foreign_currency(): void
    {
        [$user, $order, $item, $material, $currency] = $this->makeOrder('USD', '0.3333');
        $this->actingAs($user)->post(route('purchases.orders.receipts.store', $order), [
            'receipt_token' => (string) Str::uuid(),
            'received_on' => '2026-11-01',
            'quantities' => [$item->id => '0.000001'],
        ])->assertRedirect();

        $this->assertSame('0.0000003333', GoodsReceiptLine::firstOrFail()->line_subtotal_snapshot);
        $this->assertSame('USD', GoodsReceipt::firstOrFail()->cost_currency_code);
        $this->assertSame('0.000001', $material->fresh()->on_hand_quantity);
        $this->actingAs($user)->get(route('purchases.orders.show', $order))
            ->assertOk()->assertSee('0.0000003333 USD');
    }

    public function test_legacy_posted_receipt_remains_uncosted_instead_of_guessing_price(): void
    {
        [$user, $order, $item, $material] = $this->makeOrder('AFN', '10.0000');
        $receipt = GoodsReceipt::create([
            'number' => 'GRN-LEGACY', 'purchase_order_id' => $order->id,
            'receipt_token' => (string) Str::uuid(), 'received_on' => '2026-11-01',
            'status' => 'posted', 'created_by' => $user->id,
        ]);
        $receipt->lines()->create([
            'purchase_order_item_id' => $item->id,
            'inventory_material_id' => $material->id, 'quantity' => '1.000000', 'unit' => 'kg',
        ]);

        $this->assertNull($receipt->fresh()->cost_currency_code);
        $this->assertNull($receipt->lines()->firstOrFail()->line_subtotal_snapshot);
        $this->actingAs($user)->get(route('purchases.orders.show', $order))
            ->assertOk()->assertSee('Not captured');
    }
}
