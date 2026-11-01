<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionMaterialIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administration.view', 'administration.update', 'inventory.update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['administration.view', 'administration.update', 'inventory.update']);
    }

    public function test_reservation_and_actual_issue_are_exact_and_cannot_be_repeated(): void
    {
        $material = $this->material('RM-101', '10.000001');
        $order = $this->order($material, '4.000000');
        $line = $order->materials()->firstOrFail();

        $this->actingAs($this->operator)->post(route('production.orders.reserve', $order))->assertRedirect();
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame('4.000000', $material->fresh()->reserved_quantity);
        $this->assertSame('10.000001', $material->fresh()->on_hand_quantity);
        $this->actingAs($this->operator)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('Post material issue')->assertSee('4.000000');

        $payload = [
            'occurred_on' => '2026-11-01',
            'actual_quantities' => [$line->id => '3.500001'],
        ];
        $this->actingAs($this->operator)->post(route('production.orders.issue', $order), $payload)->assertRedirect();
        $this->assertSame('issued', $order->fresh()->status);
        $this->assertSame('6.500000', $material->fresh()->on_hand_quantity);
        $this->assertSame('0.000000', $material->fresh()->reserved_quantity);
        $this->assertSame('3.500001', $line->fresh()->issued_quantity);
        $this->assertSame('0.000000', $line->fresh()->reserved_quantity);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'reference_type' => 'production_order_material', 'reference_id' => $line->id,
            'quantity' => '3.500001', 'type' => 'issue',
        ]);
        $this->assertDatabaseCount('inventory_stock_movements', 2);
        $this->actingAs($this->operator)->post(route('production.orders.issue', $order), $payload)
            ->assertSessionHasErrors('production_order');
        $this->assertDatabaseCount('inventory_stock_movements', 2);
        $this->assertSame('6.500000', $material->fresh()->on_hand_quantity);
    }

    public function test_other_orders_and_manual_issues_cannot_take_reserved_stock(): void
    {
        $material = $this->material('RM-102', '10');
        $first = $this->order($material, '7');
        $second = $this->order($material, '4');

        $this->actingAs($this->operator)->post(route('production.orders.reserve', $first))->assertRedirect();
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $second))
            ->assertSessionHasErrors('production_order');
        $this->assertSame('planned', $second->fresh()->status);

        try {
            app(InventoryStockService::class)->post($material, [
                'type' => 'issue', 'direction' => 'out', 'quantity' => '4',
                'reference_type' => 'manual_issue', 'reference_id' => 100,
                'occurred_on' => '2026-11-01',
            ]);
            $this->fail('Reserved stock was consumed by an unrelated issue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertSame('10.000000', $material->fresh()->on_hand_quantity);
        $this->actingAs($this->operator)->post(route('production.orders.cancel', $first))->assertRedirect();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('0.000000', $material->fresh()->reserved_quantity);
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $second))->assertRedirect();
        $this->assertSame('4.000000', $material->fresh()->reserved_quantity);
    }

    public function test_over_issue_does_not_release_reservations_or_post_partial_stock(): void
    {
        $material = $this->material('RM-103', '10');
        $first = $this->order($material, '8');
        $second = $this->order($material, '2');
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $first))->assertRedirect();
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $second))->assertRedirect();
        $line = $first->materials()->firstOrFail();

        $this->actingAs($this->operator)->post(route('production.orders.issue', $first), [
            'occurred_on' => '2026-11-01', 'actual_quantities' => [$line->id => '9'],
        ])->assertSessionHasErrors('production_order');

        $this->assertSame('reserved', $first->fresh()->status);
        $this->assertSame('10.000000', $material->fresh()->reserved_quantity);
        $this->assertSame('10.000000', $material->fresh()->on_hand_quantity);
        $this->assertSame('8.000000', $line->fresh()->reserved_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 1);
    }

    public function test_unmapped_plan_cannot_reserve_and_mapping_is_frozen_after_reservation(): void
    {
        $material = $this->material('RM-104', '10');
        $order = $this->order($material, '2');
        $line = $order->materials()->firstOrFail();
        $line->update(['inventory_material_id' => null]);
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $order))
            ->assertSessionHasErrors('production_order');
        $this->actingAs($this->operator)->patch(route('production.orders.materials.link', [$order, $line]), [
            'inventory_material_id' => $material->id,
        ])->assertRedirect();
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $order))->assertRedirect();
        $this->actingAs($this->operator)->patch(route('production.orders.materials.link', [$order, $line]), [
            'inventory_material_id' => $material->id,
        ])->assertSessionHasErrors('inventory_material_id');
        $this->assertSame('2.000000', $material->fresh()->reserved_quantity);
    }

    public function test_reservation_requires_both_production_and_inventory_permissions(): void
    {
        $material = $this->material('RM-105', '10');
        $order = $this->order($material, '2');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('administration.view');
        $this->actingAs($viewer)->post(route('production.orders.reserve', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('production.orders.cancel', $order))->assertForbidden();
        $this->assertSame('planned', $order->fresh()->status);
    }

    public function test_availability_excludes_other_orders_reservations(): void
    {
        $material = $this->material('RM-106', '10');
        $first = $this->order($material, '7');
        $second = $this->order($material, '4');
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $first))->assertRedirect();
        $this->actingAs($this->operator)->get(route('production.orders.show', $second))
            ->assertOk()->assertSee('Short by 1.000000');
    }

    public function test_multiple_lines_using_the_same_stock_item_share_one_reservation(): void
    {
        $material = $this->material('RM-107', '10');
        $order = $this->order($material, '2');
        $first = $order->materials()->firstOrFail();
        $second = $order->materials()->create([
            'line_number' => 2, 'material_name' => 'Flour second stage', 'unit' => 'kg',
            'quantity_per_package' => '3', 'required_quantity' => '3',
            'is_packaging' => false, 'inventory_material_id' => $material->id,
        ]);
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $order))->assertRedirect();
        $this->assertSame('5.000000', $material->fresh()->reserved_quantity);
        $this->actingAs($this->operator)->post(route('production.orders.issue', $order), [
            'occurred_on' => '2026-11-01',
            'actual_quantities' => [$first->id => '2', $second->id => '2.5'],
        ])->assertRedirect();
        $this->assertSame('5.500000', $material->fresh()->on_hand_quantity);
        $this->assertSame('0.000000', $material->fresh()->reserved_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 3);
    }

    public function test_reservation_rejects_aggregate_shortage_for_shared_stock_item(): void
    {
        $material = $this->material('RM-108', '10');
        $order = $this->order($material, '6');
        $order->materials()->create([
            'line_number' => 2, 'material_name' => 'Flour second stage', 'unit' => 'kg',
            'quantity_per_package' => '6', 'required_quantity' => '6',
            'is_packaging' => false, 'inventory_material_id' => $material->id,
        ]);
        $this->actingAs($this->operator)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('Short by 2.000000');
        $this->actingAs($this->operator)->post(route('production.orders.reserve', $order))
            ->assertSessionHasErrors('production_order');
        $this->assertSame('planned', $order->fresh()->status);
        $this->assertSame('0.000000', $material->fresh()->reserved_quantity);
    }

    private function material(string $sku, string $opening): InventoryMaterial
    {
        $material = InventoryMaterial::create(['sku' => $sku, 'name' => 'Flour', 'unit' => 'kg']);
        app(InventoryStockService::class)->post($material, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => $opening,
            'reference_type' => 'opening', 'reference_id' => $material->id,
            'occurred_on' => '2026-11-01',
        ]);
        return $material;
    }

    private function order(InventoryMaterial $material, string $quantity): ProductionOrder
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Flour formula', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-'.str_pad((string) (ProductionOrder::count() + 1), 6, '0', STR_PAD_LEFT),
            'production_recipe_id' => $recipe->id, 'package_count' => 1,
        ]);
        $order->materials()->create([
            'line_number' => 1, 'material_name' => 'Flour', 'unit' => 'kg',
            'quantity_per_package' => $quantity, 'required_quantity' => $quantity,
            'is_packaging' => false, 'inventory_material_id' => $material->id,
        ]);
        return $order;
    }
}
