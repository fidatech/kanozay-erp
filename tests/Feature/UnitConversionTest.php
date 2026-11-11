<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionRecipe;
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\UnitConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * M10: explicit, directional unit conversions, and their use when a production requirement
 * written in a recipe unit is linked to a stock item kept in another unit.
 */
class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['inventory.view', 'inventory.update', 'administration.view', 'administration.update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['inventory.view', 'inventory.update', 'administration.view', 'administration.update']);
    }

    private function order(string $number): ProductionOrder
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Synthetic blend '.$number, 'method' => 'powder_blending', 'package_weight_kg' => 1, 'package_unit' => 'bag', 'status' => 'approved',
        ]);

        return ProductionOrder::create(['number' => $number, 'production_recipe_id' => $recipe->id, 'package_count' => 20]);
    }

    private function requirement(ProductionOrder $order, int $number, string $unit, string $perPackage, string $required): ProductionOrderMaterial
    {
        return $order->materials()->create([
            'line_number' => $number, 'material_name' => 'Synthetic input '.$number, 'unit' => $unit,
            'quantity_per_package' => $perPackage, 'required_quantity' => $required, 'is_packaging' => false,
        ]);
    }

    private function define(array $data)
    {
        return $this->actingAs($this->user)->post(route('inventory.unit-conversions.store'), $data);
    }

    private function link(ProductionOrder $order, ProductionOrderMaterial $line, InventoryMaterial $material)
    {
        return $this->actingAs($this->user)->patch(route('production.orders.materials.link', [$order, $line]), ['inventory_material_id' => $material->id]);
    }

    public function test_conversions_are_explicit_directional_and_validated(): void
    {
        $this->define(['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertRedirect()->assertSessionHasNoErrors();
        $this->define(['from_unit' => ' G ', 'to_unit' => 'KG', 'factor' => '0.002'])->assertSessionHasErrors('from_unit'); // duplicate direction
        $this->define(['from_unit' => 'kg', 'to_unit' => 'KG', 'factor' => '1'])->assertSessionHasErrors('to_unit');
        $this->define(['from_unit' => 'ml', 'to_unit' => 'l', 'factor' => '0'])->assertSessionHasErrors('factor');
        $this->define(['from_unit' => 'ml', 'to_unit' => 'l', 'factor' => '-1'])->assertSessionHasErrors('factor');
        $this->define(['from_unit' => 'ml', 'to_unit' => 'l', 'factor' => '1e-3'])->assertSessionHasErrors('factor');
        $this->assertDatabaseCount('unit_conversions', 1);

        $units = app(UnitConversionService::class);
        $this->assertSame('2.500000', $units->convert('2500', 'g', 'kg'));
        $this->assertSame('2.500000', $units->convert('2500', 'G', ' Kg '));
        $this->assertSame('7.000000', $units->convert('7', 'kg', 'kg'));
        // The reverse direction is not assumed.
        $this->assertNull($units->factor('kg', 'g'));
        try {
            $units->convert('1', 'kg', 'g');
            $this->fail('An undefined direction must not convert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
        // A result that needs more than six decimals is refused, never rounded.
        try {
            $units->convert('0.0005', 'g', 'kg');
            $this->fail('An inexact conversion must not be rounded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }

        // An item-specific factor wins over the general one for that item only.
        $carton = InventoryMaterial::create(['sku' => 'UC-CARTON', 'name' => 'Synthetic boxed spice', 'unit' => 'kg']);
        $this->define(['from_unit' => 'carton', 'to_unit' => 'kg', 'factor' => '10'])->assertRedirect()->assertSessionHasNoErrors();
        $this->define(['from_unit' => 'carton', 'to_unit' => 'kg', 'factor' => '12', 'inventory_material_id' => $carton->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('36.000000', $units->convert('3', 'carton', 'kg', $carton->id));
        $this->assertSame('30.000000', $units->convert('3', 'carton', 'kg'));
        $this->assertSame('30.000000', $units->convert('3', 'carton', 'kg', $carton->id + 999));

        // Deactivation is audited, cannot repeat, and stops further use.
        $general = UnitConversion::where('from_unit', 'g')->firstOrFail();
        $this->actingAs($this->user)->post(route('inventory.unit-conversions.deactivate', $general))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('inventory.unit-conversions.deactivate', $general))->assertSessionHasErrors('conversion');
        $this->assertNull($units->factor('g', 'kg'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'inventory.unit_conversion.deactivated', 'subject_id' => $general->id]);
        // After deactivation a new factor for the same direction may be defined.
        $this->define(['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get(route('inventory.unit-conversions.index'))->assertOk()->assertSee('Unit conversions')->assertSee('UC-CARTON');
        $this->actingAs($this->user)->get(route('inventory.unit-conversions.index', ['status' => 'inactive']))->assertOk()->assertSee('Inactive');
    }

    public function test_recipe_requirement_is_converted_into_the_stock_unit_when_linked_and_then_reserved(): void
    {
        $order = $this->order('PRD-UC-1');
        $line = $this->requirement($order, 1, 'g', '125.000000', '2500.000000');
        $stockItem = InventoryMaterial::create(['sku' => 'UC-RAW-KG', 'name' => 'Synthetic paprika', 'unit' => 'kg']);

        // No conversion defined: the link is refused and nothing changes.
        $this->link($order, $line, $stockItem)->assertSessionHasErrors('inventory_material_id');
        $this->assertNull($line->fresh()->inventory_material_id);
        $this->assertSame('g', $line->fresh()->unit);

        $this->define(['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertRedirect()->assertSessionHasNoErrors();
        $this->link($order, $line, $stockItem)->assertRedirect()->assertSessionHasNoErrors();
        $converted = $line->fresh();
        $this->assertSame($stockItem->id, (int) $converted->inventory_material_id);
        $this->assertSame('kg', $converted->unit);
        $this->assertSame('2.500000', $converted->required_quantity);
        $this->assertSame('0.125000', $converted->quantity_per_package);
        $this->assertSame('g', $converted->source_unit);
        $this->assertEquals(2500, (float) $converted->source_required_quantity);
        $this->assertEquals(125, (float) $converted->source_quantity_per_package);
        $this->assertEquals(0.001, (float) $converted->unit_conversion_factor);
        $this->assertDatabaseHas('activity_logs', ['action' => 'production.material.unit_converted', 'subject_id' => $line->id]);

        // Linking again does not convert twice.
        $this->link($order, $line, $stockItem)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2.500000', $line->fresh()->required_quantity);

        // Re-linking to an item kept in the recipe unit restores the recipe values.
        $gramItem = InventoryMaterial::create(['sku' => 'UC-RAW-G', 'name' => 'Synthetic paprika sachet stock', 'unit' => 'g']);
        $this->link($order, $line, $gramItem)->assertRedirect()->assertSessionHasNoErrors();
        $restored = $line->fresh();
        $this->assertSame('g', $restored->unit);
        $this->assertSame('2500.000000', $restored->required_quantity);
        $this->assertNull($restored->source_unit);
        $this->assertNull($restored->unit_conversion_factor);
        $this->link($order, $line, $stockItem)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2.500000', $line->fresh()->required_quantity);

        // A requirement that cannot be converted exactly is refused and left untouched.
        $tiny = $this->requirement($order, 2, 'g', '0.000025', '0.000500');
        $this->link($order, $tiny, $stockItem)->assertSessionHasErrors('inventory_material_id');
        $this->assertNull($tiny->fresh()->inventory_material_id);
        $this->assertSame('0.000500', $tiny->fresh()->required_quantity);
        $tiny->delete();

        // Ingredient and packaging items still cannot be mixed.
        $bag = InventoryMaterial::create(['sku' => 'UC-BAG', 'name' => 'Synthetic bag', 'unit' => 'kg', 'is_packaging' => true]);
        $this->link($order, $line, $bag)->assertSessionHasErrors('inventory_material_id');

        // Reservation works in the stock unit: 2.5 kg of 3 kg.
        app(InventoryStockService::class)->post($stockItem, [
            'type' => 'opening', 'direction' => 'in', 'quantity' => '3.000000', 'reference_type' => 'opening', 'reference_id' => $stockItem->id,
            'occurred_on' => today()->subDays(2)->toDateString(), 'created_by' => $this->user->id,
        ]);
        $this->actingAs($this->user)->post(route('production.orders.reserve', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame('2.500000', $stockItem->fresh()->reserved_quantity);
        $this->assertSame('2.500000', $line->fresh()->reserved_quantity);

        // After reservation the link, and therefore the conversion, is frozen even if the factor is retired.
        $this->actingAs($this->user)->post(route('inventory.unit-conversions.deactivate', UnitConversion::firstOrFail()))->assertRedirect();
        $this->link($order, $line, $gramItem)->assertSessionHasErrors('inventory_material_id');
        $this->assertSame('2.500000', $line->fresh()->required_quantity);
        $this->assertSame('kg', $line->fresh()->unit);

        $this->actingAs($this->user)->get(route('production.orders.show', $order))->assertOk();
    }

    public function test_unit_conversions_require_inventory_permissions(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('inventory.unit-conversions.index'))->assertForbidden();
        $this->actingAs($outsider)->post(route('inventory.unit-conversions.store'), ['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertForbidden();
        $outsider->givePermissionTo('inventory.view');
        $this->actingAs($outsider)->get(route('inventory.unit-conversions.index'))->assertOk()->assertDontSee('Add a conversion');
        $this->actingAs($outsider)->post(route('inventory.unit-conversions.store'), ['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertForbidden();
        $this->define(['from_unit' => 'g', 'to_unit' => 'kg', 'factor' => '0.001'])->assertRedirect();
        $this->actingAs($outsider)->post(route('inventory.unit-conversions.deactivate', UnitConversion::firstOrFail()))->assertForbidden();
        $this->assertDatabaseCount('unit_conversions', 1);
        $this->assertTrue((bool) UnitConversion::firstOrFail()->is_active);
    }
}
