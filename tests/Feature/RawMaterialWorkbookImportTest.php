<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use App\Models\InventoryStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RawMaterialWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_validates_rows_and_confirmation_posts_stock_and_cost_layers(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.update', 'web');
        $user->givePermissionTo('inventory.update');
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'is_base' => false]);
        $path = $this->workbook([['Citric Acid', 'kg', 12.5, 2.25]]);

        $this->actingAs($user)->post(route('inventory.materials.import-preview'), [
            'file' => new UploadedFile($path, 'raw-stock.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->assertSee('Citric Acid')->assertSee('12.5')->assertSee('Confirm import');
        $this->assertSame(0, InventoryMaterial::count());

        $this->actingAs($user)->post(route('inventory.materials.import-confirm'), [
            'occurred_on' => now()->toDateString(), 'currency_id' => $usd->id, 'confirm_import' => '1',
        ])->assertRedirect(route('inventory.materials.index'));

        $material = InventoryMaterial::sole();
        $this->assertSame('12.500000', $material->on_hand_quantity);
        $movement = InventoryStockMovement::sole();
        $this->assertSame('opening', $movement->type);
        $layer = InventoryCostLayer::sole();
        $this->assertSame('USD', $layer->currency_code);
        $this->assertSame('28.1250000000', $layer->original_cost);
        $this->assertSame(1, InventoryMaterial::count());
    }

    public function test_duplicate_material_name_and_unit_is_reported_before_confirmation(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('inventory.update', 'web');
        $user->givePermissionTo('inventory.update');
        $path = $this->workbook([['Sugar', 'kg', 10, 1], ['Sugar', 'kg', 4, 1]]);

        $this->actingAs($user)->post(route('inventory.materials.import-preview'), [
            'file' => new UploadedFile($path, 'raw-stock.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->assertSee('duplicated within this workbook');
        $this->assertSame(0, InventoryMaterial::count());
        $this->assertSame(0, InventoryStockMovement::count());
    }

    private function workbook(array $items): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Raw Materials Stock In Factory');
        $sheet->fromArray(['Sr #', 'Details', null, null, null, 'Unit', 'Qty', 'Price USD'], null, 'A2');
        foreach ($items as $index => [$name, $unit, $quantity, $price]) {
            $sheet->fromArray([$index + 1, $name, null, null, null, $unit, $quantity, $price], null, 'A'.($index + 3));
        }
        $path = tempnam(sys_get_temp_dir(), 'kanozay-stock-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }
}
