<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryMovementHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_inspect_immutable_material_movements(): void
    {
        $material = InventoryMaterial::create([
            'sku' => 'RM-HISTORY', 'name' => 'Flour', 'unit' => 'kg',
        ]);
        $stock = app(InventoryStockService::class);
        $stock->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '10',
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => '2026-11-01',
        ]);
        $stock->post($material, [
            'type' => 'issue', 'direction' => 'out', 'quantity' => '1',
            'reference_type' => 'manual_issue', 'reference_id' => 23,
            'occurred_on' => '2026-11-01', 'notes' => 'Test issue',
        ]);

        Permission::findOrCreate('inventory.view', 'web');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('inventory.view');

        $this->actingAs($viewer)->get(route('inventory.materials.index'))
            ->assertOk()->assertSee(route('inventory.materials.show', $material));
        $this->actingAs($viewer)->get(route('inventory.materials.show', $material))
            ->assertOk()->assertSee('Stock movement history')
            ->assertSee('RM-HISTORY')->assertSee('9.000000')
            ->assertSee('Opening')->assertSee('Manual issue')
            ->assertSee('Test issue');
        $this->assertDatabaseCount('inventory_stock_movements', 2);
    }

    public function test_user_without_inventory_view_cannot_read_movement_history(): void
    {
        $material = InventoryMaterial::create([
            'sku' => 'RM-HIDDEN', 'name' => 'Flour', 'unit' => 'kg',
        ]);
        $this->actingAs(User::factory()->create())
            ->get(route('inventory.materials.show', $material))->assertForbidden();
    }
}
