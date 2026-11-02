<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryValuationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_valuation_report_requires_view_permission_and_discloses_unvalued_stock(): void
    {
        $user = User::factory()->create();
        $material = InventoryMaterial::create(['sku' => 'VAL-001', 'name' => 'Synthetic Flour', 'unit' => 'kg']);
        app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '12.500000',
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('inventory.valuation'))->assertForbidden();
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        $this->actingAs($user)->get(route('inventory.valuation'))
            ->assertOk()->assertSee('Raw-material valuation')->assertSee('Synthetic Flour')
            ->assertSee('12.5')->assertSee('Unvalued');
    }
}
