<?php

namespace Tests\Feature;

use App\Models\{Currency, ExchangeRate, GoodsReceipt, InventoryMaterial, PurchaseOrder, TradeParty, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GoodsReceiptFxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeForeignOrder(): array
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.update', 'web');
        Permission::findOrCreate('administration.view', 'web');
        $user->givePermissionTo(['inventory.update', 'administration.view']);
        $afn = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_base' => true, 'is_active' => true]);
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'is_base' => false, 'is_active' => true]);
        $supplier = TradeParty::create([
            'type' => 'supplier', 'name' => 'FX Supplier', 'code' => 'SUP-FX-'.Str::random(8),
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $material = InventoryMaterial::create(['sku' => 'FX-'.Str::random(8), 'name' => 'FX Flour', 'unit' => 'kg']);
        $order = PurchaseOrder::create([
            'number' => 'PO-FX-'.Str::random(8), 'supplier_id' => $supplier->id,
            'currency_id' => $usd->id, 'source' => 'foreign', 'order_date' => '2026-11-01',
            'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        $item = $order->items()->create([
            'description' => 'Flour', 'quantity' => '10.0000',
            'unit_price' => '10.0000', 'inventory_material_id' => $material->id,
        ]);

        return [$user, $afn, $usd, $order, $item, $material];
    }

    private function receive(User $user, PurchaseOrder $order, int $itemId, string $quantity = '3.000000'): void
    {
        $this->actingAs($user)->post(route('purchases.orders.receipts.store', $order), [
            'receipt_token' => (string) Str::uuid(), 'received_on' => '2026-11-01',
            'quantities' => [$itemId => $quantity],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_latest_direct_rate_on_or_before_receipt_is_frozen_with_source(): void
    {
        [$user, $afn, $usd, $order, $item, $material] = $this->makeForeignOrder();
        $old = ExchangeRate::create([
            'from_currency_id' => $usd->id, 'to_currency_id' => $afn->id,
            'rate' => '71.000000000000', 'effective_date' => '2026-10-31', 'created_by' => $user->id,
        ]);
        ExchangeRate::create([
            'from_currency_id' => $usd->id, 'to_currency_id' => $afn->id,
            'rate' => '72.000000000000', 'effective_date' => '2026-11-01', 'created_by' => $user->id,
        ]);
        $selected = ExchangeRate::create([
            'from_currency_id' => $usd->id, 'to_currency_id' => $afn->id,
            'rate' => '73.000000000000', 'effective_date' => '2026-11-01', 'created_by' => $user->id,
        ]);
        ExchangeRate::create([
            'from_currency_id' => $usd->id, 'to_currency_id' => $afn->id,
            'rate' => '90.000000000000', 'effective_date' => '2026-11-02', 'created_by' => $user->id,
        ]);

        $this->receive($user, $order, $item->id);
        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame($afn->id, $receipt->valuation_currency_id);
        $this->assertSame('AFN', $receipt->valuation_currency_code);
        $this->assertSame('73.000000000000', $receipt->fx_rate_snapshot);
        $this->assertSame($selected->id, $receipt->fx_rate_source_id);
        $this->assertSame('2026-11-01', $receipt->fx_rate_effective_date->toDateString());
        $this->assertSame('30 USD', $receipt->purchase_value_snapshot);
        $this->assertSame('2190 AFN', $receipt->base_purchase_value_snapshot);
        $this->assertSame('3.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('journal_entries', 0);

        $selected->update(['rate' => '99.000000000000']);
        $this->assertSame('2190 AFN', $receipt->fresh()->base_purchase_value_snapshot);
        $this->actingAs($user)->get(route('purchases.orders.show', $order))
            ->assertOk()->assertSee('2190 AFN')->assertSee('73.000000000000');
    }

    public function test_missing_direct_historical_rate_does_not_block_stock_or_invent_conversion(): void
    {
        [$user, $afn, $usd, $order, $item, $material] = $this->makeForeignOrder();
        ExchangeRate::create([
            'from_currency_id' => $afn->id, 'to_currency_id' => $usd->id,
            'rate' => '0.014000000000', 'effective_date' => '2026-11-01', 'created_by' => $user->id,
        ]);
        ExchangeRate::create([
            'from_currency_id' => $usd->id, 'to_currency_id' => $afn->id,
            'rate' => '75.000000000000', 'effective_date' => '2026-11-02', 'created_by' => $user->id,
        ]);
        $this->receive($user, $order, $item->id);
        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame('30 USD', $receipt->purchase_value_snapshot);
        $this->assertNull($receipt->fx_rate_snapshot);
        $this->assertNull($receipt->base_purchase_value_snapshot);
        $this->assertSame('3.000000', $material->fresh()->on_hand_quantity);
        $this->actingAs($user)->get(route('purchases.orders.show', $order))
            ->assertOk()->assertSee('FX rate unavailable');
    }

    public function test_purchase_in_base_currency_uses_exact_identity_rate_without_rate_record(): void
    {
        [$user, $afn, $usd, $order, $item] = $this->makeForeignOrder();
        $order->update(['currency_id' => $afn->id]);
        $this->receive($user, $order, $item->id, '0.125000');
        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame('1.000000000000', $receipt->fx_rate_snapshot);
        $this->assertNull($receipt->fx_rate_source_id);
        $this->assertNull($receipt->fx_rate_effective_date);
        $this->assertSame('1.25 AFN', $receipt->base_purchase_value_snapshot);
    }
}
