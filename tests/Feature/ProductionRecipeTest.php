<?php

namespace Tests\Feature;

use App\Models\ProductionRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionRecipeTest extends TestCase {
    use RefreshDatabase;

    public function test_recipe_preserves_source_reference_and_ingredient_precision(): void {
        $recipe = ProductionRecipe::create([
            'name' => 'Prime Glucose KS1250',
            'method' => 'powder_blending',
            'package_weight_kg' => 25,
            'package_unit' => 'bag',
            'cost_currency_code' => 'USD',
            'source_sheet' => 'Production Method 2',
            'source_reference' => 'Production Types and Recipes Formula.xlsx',
        ]);
        $recipe->lines()->create([
            'line_number' => 1,
            'material_name' => 'Dextrose Anhydrouse (75)',
            'unit' => 'Kg',
            'quantity_per_package' => 11.75,
            'unit_cost' => 3.3,
            'source_cell' => 'F6',
        ]);
        $this->assertSame('draft', $recipe->fresh()->status);
        $this->assertSame('Production Method 2', $recipe->fresh()->source_sheet);
        $this->assertEquals(11.75, $recipe->lines()->firstOrFail()->quantity_per_package);
        $this->assertSame(1, $recipe->lines()->count());
    }
}
