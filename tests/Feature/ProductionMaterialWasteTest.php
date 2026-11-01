<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionMaterialWasteTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('manufacturing.update', 'web');
        Permission::findOrCreate('administration.view', 'web');
        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['manufacturing.update', 'administration.view']);
    }

    public function test_waste_is_limited_to_issued_material_and_never_deducts_inventory_again(): void
    {
        [$order, $line] = $this->orderWithIssuedMaterial('issued');
        $token = (string) Str::uuid();
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$order, $line]), [
            'submission_token' => $token, 'quantity' => '2.000001', 'reason' => 'Damaged during blending',
        ])->assertRedirect();
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$order, $line]), [
            'submission_token' => $token, 'quantity' => '2.000001', 'reason' => 'Duplicate submission',
        ])->assertSessionHasErrors('production_waste');
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$order, $line]), [
            'submission_token' => (string) Str::uuid(), 'quantity' => '2.999999', 'reason' => 'Further rejects',
        ])->assertRedirect();
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$order, $line]), [
            'submission_token' => (string) Str::uuid(), 'quantity' => '0.000001', 'reason' => 'Over reported',
        ])->assertSessionHasErrors('production_waste');
        $this->assertDatabaseCount('production_material_wastes', 2);
        $this->assertDatabaseCount('inventory_stock_movements', 0);
        $this->assertSame('5.000000', $line->fresh()->issued_quantity);
        $this->actingAs($this->operator)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('5.000000')->assertSee('Record waste');
    }

    public function test_waste_is_frozen_after_lab_approval_and_rejected_for_other_orders(): void
    {
        [$order, $line] = $this->orderWithIssuedMaterial('qc_approved');
        $payload = [
            'submission_token' => (string) Str::uuid(),
            'quantity' => '1', 'reason' => 'Damaged material',
        ];
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$order, $line]), $payload)
            ->assertSessionHasErrors('production_waste');
        $other = ProductionOrder::create([
            'number' => 'PRD-000002', 'production_recipe_id' => $order->production_recipe_id,
            'package_count' => 1, 'status' => 'issued',
        ]);
        $this->actingAs($this->operator)->post(route('production.orders.materials.waste', [$other, $line]), $payload)
            ->assertNotFound();
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->post(route('production.orders.materials.waste', [$order, $line]), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('production_material_wastes', 0);
    }

    private function orderWithIssuedMaterial(string $status): array
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Waste test recipe', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-000001', 'production_recipe_id' => $recipe->id,
            'package_count' => 1, 'status' => $status,
        ]);
        $line = $order->materials()->create([
            'line_number' => 1, 'material_name' => 'Flour', 'unit' => 'kg',
            'quantity_per_package' => '5', 'required_quantity' => '5',
            'issued_quantity' => '5.000000', 'is_packaging' => false,
        ]);
        return [$order, $line];
    }
}
