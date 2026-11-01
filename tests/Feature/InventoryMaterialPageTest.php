<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryMaterialPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_creation_and_opening_stock_respect_permissions(): void
    {
        $user = User::factory()->create();
        foreach (['inventory.view', 'inventory.create', 'inventory.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->actingAs($user)->get(route('inventory.materials.index'))->assertForbidden();
        $user->givePermissionTo('inventory.view');
        $this->actingAs($user)->get(route('inventory.materials.index'))->assertOk();
        $this->actingAs($user)->post(route('inventory.materials.store'), [
            'sku' => 'rm-001', 'name' => 'Flour', 'unit' => 'kg', 'is_packaging' => 0,
        ])->assertForbidden();

        $user->givePermissionTo('inventory.create');
        $this->actingAs($user)->post(route('inventory.materials.store'), [
            'sku' => 'rm-001', 'name' => 'Flour', 'unit' => 'kg', 'is_packaging' => 0,
        ])->assertRedirect(route('inventory.materials.index'));
        $material = InventoryMaterial::firstOrFail();
        $this->assertSame('RM-001', $material->sku);
        $this->assertSame('0.000000', $material->on_hand_quantity);

        $opening = ['quantity' => '12.000001', 'occurred_on' => '2026-11-01'];
        $this->actingAs($user)->post(route('inventory.materials.opening', $material), $opening)->assertForbidden();
        $user->givePermissionTo('inventory.update');
        $this->actingAs($user)->post(route('inventory.materials.opening', $material), $opening)
            ->assertRedirect(route('inventory.materials.index'));
        $this->assertSame('12.000001', $material->fresh()->on_hand_quantity);
        $this->actingAs($user)->get(route('inventory.materials.index'))->assertOk()->assertSee('12.000001');
        $this->actingAs($user)->post(route('inventory.materials.opening', $material), $opening)
            ->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('inventory_stock_movements', 1);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.create', 'web');
        $user->givePermissionTo('inventory.create');
        InventoryMaterial::create(['sku' => 'RM-1', 'name' => 'Existing', 'unit' => 'kg']);
        $this->actingAs($user)->post(route('inventory.materials.store'), [
            'sku' => 'rm-1', 'name' => 'Duplicate', 'unit' => 'kg', 'is_packaging' => 0,
        ])->assertSessionHasErrors('sku');
        $this->assertDatabaseCount('inventory_materials', 1);
    }
}
