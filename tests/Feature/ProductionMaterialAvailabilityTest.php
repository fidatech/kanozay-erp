<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionMaterialAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_order_shows_shortage_without_reserving_or_consuming_stock(): void
    {
        $user = User::factory()->create();
        foreach (['administration.view', 'administration.update', 'inventory.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['administration.view', 'administration.update', 'inventory.update']);
        $recipe = ProductionRecipe::create([
            'name' => 'Flour bags', 'method' => 'powder_blending', 'package_weight_kg' => 5,
            'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $recipe->lines()->create([
            'line_number' => 1, 'material_name' => 'Flour', 'unit' => 'kg',
            'quantity_per_package' => '5.000000', 'is_packaging' => false,
        ]);
        $material = InventoryMaterial::create(['sku' => 'RM-001', 'name' => 'Flour', 'unit' => 'kg']);
        app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '10.000000',
            'reference_type' => 'opening', 'reference_id' => $material->id, 'occurred_on' => '2026-11-01',
        ]);
        $this->actingAs($user)->post(route('production.orders.store'), [
            'production_recipe_id' => $recipe->id, 'package_count' => 3,
        ])->assertRedirect();
        $order = ProductionOrder::firstOrFail();
        $line = $order->materials()->firstOrFail();
        $this->actingAs($user)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('Not mapped');
        $this->actingAs($user)->patch(route('production.orders.materials.link', [$order, $line]), [
            'inventory_material_id' => $material->id,
        ])->assertRedirect();
        $this->actingAs($user)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('RM-001')->assertSee('Short by 5.000000');
        $this->assertSame('10.000000', $material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 1);
        $this->assertSame('15.000000', $line->fresh()->required_quantity);
    }

    public function test_material_link_rejects_unit_or_packaging_mismatch(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.update', 'web');
        $user->givePermissionTo('inventory.update');
        $recipe = ProductionRecipe::create([
            'name' => 'Recipe', 'method' => 'powder_blending',
            'package_weight_kg' => 1, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-000001', 'production_recipe_id' => $recipe->id, 'package_count' => 1,
        ]);
        $line = $order->materials()->create([
            'line_number' => 1, 'material_name' => 'Flour', 'unit' => 'kg',
            'quantity_per_package' => '1', 'required_quantity' => '1', 'is_packaging' => false,
        ]);
        $wrong = InventoryMaterial::create([
            'sku' => 'PK-001', 'name' => 'Bag', 'unit' => 'kg', 'is_packaging' => true,
        ]);
        $this->actingAs($user)->patch(route('production.orders.materials.link', [$order, $line]), [
            'inventory_material_id' => $wrong->id,
        ])->assertSessionHasErrors('inventory_material_id');
        $this->assertNull($line->fresh()->inventory_material_id);
    }
}
