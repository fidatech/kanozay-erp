<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\InventoryStockAdjustment;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create();
        foreach (['inventory.view', 'inventory.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['inventory.view', 'inventory.update']);
        $material = InventoryMaterial::create(['sku' => 'RM-ADJ-1', 'name' => 'Synthetic adjustment stock', 'unit' => 'kg']);
        app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '10.000001',
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => '2020-01-01', 'created_by' => $user->id,
        ]);

        return [$user, $material];
    }

    public function test_authorized_adjustments_update_balance_and_are_idempotent(): void
    {
        [$user, $material] = $this->fixtures();
        $payload = [
            'direction' => 'adjustment_out',
            'quantity' => '2.000001',
            'occurred_on' => '2026-10-31',
            'reason' => 'Physical stock count correction',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertRedirect();
        $this->assertSame('8.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments', 1);
        $this->assertDatabaseCount('inventory_stock_movements', 2);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'reference_type' => 'inventory_adjustment',
            'type' => 'adjustment_out',
            'quantity' => '2.000001',
        ]);

        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertRedirect();
        $this->assertSame('8.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments', 1);
        $this->assertDatabaseCount('inventory_stock_movements', 2);

        $this->actingAs($user)->get(route('inventory.materials.show', $material))
            ->assertOk()->assertSee('Physical stock count correction')->assertSee('Inventory adjustment #');
    }

    public function test_adjustment_cannot_reduce_stock_below_zero_or_reserved_quantity(): void
    {
        [$user, $material] = $this->fixtures();
        $payload = [
            'direction' => 'adjustment_out', 'quantity' => '10.000002', 'occurred_on' => '2026-10-31',
            'reason' => 'Correct an over-count', 'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertSessionHasErrors('quantity');
        $this->assertSame('10.000001', $material->fresh()->on_hand_quantity);

        $material->forceFill(['reserved_quantity' => '8.000000'])->save();
        $payload['quantity'] = '3.000000';
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertSessionHasErrors('quantity');
        $this->assertSame('10.000001', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments', 0);
        $this->assertDatabaseCount('inventory_stock_movements', 1);
    }

    public function test_reused_adjustment_key_with_different_payload_is_rejected(): void
    {
        [$user, $material] = $this->fixtures();
        $key = (string) Str::uuid();
        $payload = [
            'direction' => 'adjustment_in', 'quantity' => '1.5', 'occurred_on' => '2026-10-31',
            'reason' => 'Approved physical recount', 'idempotency_key' => $key,
        ];
        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), array_merge($payload, ['quantity' => '2']))
            ->assertSessionHasErrors('idempotency_key');
        $this->assertSame('11.500001', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments', 1);
        $this->assertDatabaseCount('inventory_stock_movements', 2);
    }

    public function test_adjustments_require_inventory_update_permission_and_cannot_be_deleted(): void
    {
        [$user, $material] = $this->fixtures();
        $viewer = User::factory()->create();
        $user->givePermissionTo('inventory.update');
        $payload = [
            'direction' => 'adjustment_in', 'quantity' => '1', 'occurred_on' => '2026-10-31',
            'reason' => 'Verified physical recount', 'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($viewer)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertForbidden();
        $this->assertDatabaseCount('inventory_stock_adjustments', 0);
        $this->actingAs($user)->post(route('inventory.materials.adjustments.store', $material), $payload)->assertRedirect();

        $adjustment = InventoryStockAdjustment::firstOrFail();
        try {
            $adjustment->delete();
            $this->fail('Posted adjustments must be immutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
        $this->assertDatabaseHas('inventory_stock_adjustments', ['id' => $adjustment->id]);
    }
}
