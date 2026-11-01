<?php

namespace Tests\Feature;

use App\Models\FinishedGood;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use App\Services\ProductionBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionOrderRecipeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_preserves_package_specification_and_output_after_recipe_changes(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('manufacturing.create', 'web');
        Permission::findOrCreate('manufacturing.view', 'web');
        $user->givePermissionTo(['manufacturing.create', 'manufacturing.view']);

        $recipe = $this->recipe();
        $this->actingAs($user)->post(route('production.orders.store'), [
            'production_recipe_id' => $recipe->id, 'package_count' => 3,
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();
        $this->assertSame('Original formula', $order->recipe_name_snapshot);
        $this->assertSame('FG-ORIGINAL', $order->product_code_snapshot);
        $this->assertSame('5.0000', $order->package_weight_kg_snapshot);
        $this->assertSame('bag', $order->package_unit_snapshot);

        $recipe->update([
            'name' => 'Revised formula', 'product_code' => 'FG-NEW',
            'package_weight_kg' => '10.0000', 'package_unit' => 'box',
        ]);

        $this->assertSame('5.0000', $order->fresh()->planned_package_weight_kg);
        $this->assertSame('FG-ORIGINAL', $order->fresh()->planned_product_code);
        $this->actingAs($user)->get(route('production.orders.show', $order))
            ->assertOk()->assertSee('Original formula')->assertSee('15.0000 kg planned output');
    }

    public function test_batch_validation_uses_original_plan_not_modified_recipe(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('manufacturing.create', 'web');
        $user->givePermissionTo('manufacturing.create');
        $recipe = $this->recipe();
        $this->actingAs($user)->post(route('production.orders.store'), [
            'production_recipe_id' => $recipe->id, 'package_count' => 3,
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();
        $order->update(['status' => 'issued']); // Isolate batch specification validation.
        $original = FinishedGood::create([
            'sku' => 'FG-ORIGINAL', 'name' => 'Original bag',
            'package_unit' => 'bag', 'package_weight_kg' => '5.000',
        ]);
        $revised = FinishedGood::create([
            'sku' => 'FG-NEW', 'name' => 'Revised box',
            'package_unit' => 'box', 'package_weight_kg' => '10.000',
        ]);
        $recipe->update([
            'product_code' => 'FG-NEW', 'package_weight_kg' => '10.0000',
            'package_unit' => 'box',
        ]);

        try {
            app(ProductionBatchService::class)->submit($order, $revised, 2, null, $user->id);
            $this->fail('A product matching only the revised recipe must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production_batch', $exception->errors());
        }

        $batch = app(ProductionBatchService::class)->submit($order, $original, 2, null, $user->id);
        $this->assertSame($original->id, $batch->finished_good_id);
        $this->assertSame('pending_qc', $order->fresh()->status);
    }

    private function recipe(): ProductionRecipe
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Original formula', 'product_code' => 'FG-ORIGINAL',
            'method' => 'powder_blending', 'package_weight_kg' => '5.0000',
            'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $recipe->lines()->create([
            'line_number' => 1, 'material_name' => 'Ingredient',
            'unit' => 'kg', 'quantity_per_package' => '5.000000',
        ]);

        return $recipe;
    }
}
