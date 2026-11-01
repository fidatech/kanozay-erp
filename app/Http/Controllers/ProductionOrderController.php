<?php
namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\InventoryMaterial;
use App\Models\ProductionOrderMaterial;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionOrderController extends Controller {
    public function index(Request $request) {
        abort_unless($request->user()->can('administration.view'), 403);
        return view('production.orders', [
            'orders' => ProductionOrder::with('recipe')->latest('id')->paginate(20),
            'recipes' => ProductionRecipe::where('status', 'approved')->orderBy('name')->get(),
        ]);
    }
    public function store(Request $request) {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate([
            'production_recipe_id' => ['required', Rule::exists('production_recipes', 'id')->where('status', 'approved')],
            'package_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = DB::transaction(function () use ($request, $data) {
            $recipe = ProductionRecipe::whereKey($data['production_recipe_id'])->lockForUpdate()->firstOrFail();
            abort_unless($recipe->status === 'approved', 422, 'Recipe must be approved.');
            $lines = $recipe->lines()->get();
            abort_if($lines->isEmpty(), 422, 'Approved recipe has no material lines.');
            $order = ProductionOrder::create([
                'number' => 'PO-PENDING-'.bin2hex(random_bytes(8)),
                'production_recipe_id' => $recipe->id,
                'package_count' => $data['package_count'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            foreach ($lines as $line) {
                $quantity = (string) $line->quantity_per_package;
                [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
                $count = (int) $data['package_count'];
                $fractionMicros = (int) str_pad(substr($fraction, 0, 6), 6, '0');
                $fractionTotal = $fractionMicros * $count;
                $requiredWhole = (int) $whole * $count + intdiv($fractionTotal, 1000000);
                $required = $requiredWhole.'.'.str_pad((string) ($fractionTotal % 1000000), 6, '0', STR_PAD_LEFT);
                $order->materials()->create([
                    'line_number' => $line->line_number,
                    'material_name' => $line->material_name,
                    'unit' => $line->unit,
                    'quantity_per_package' => $quantity,
                    'required_quantity' => $required,
                    'is_packaging' => $line->is_packaging,
                ]);
            }
            $order->update(['number' => 'PRD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
            return $order;
        });
        return redirect()->route('production.orders.show', $order)->with('success', 'Production plan created. No stock has been reserved or consumed.');
    }
    public function show(Request $request, ProductionOrder $order) {
        abort_unless($request->user()->can('administration.view'), 403);
        $order->load('recipe', 'materials.inventoryMaterial');
        $materials = $order->materials->map(function ($line) {
            $catalog = $line->inventoryMaterial;
            $onHand = $catalog ? (string) $catalog->on_hand_quantity : null;
            $shortage = $onHand === null ? null
                : BigDecimal::of((string) $line->required_quantity)->minus(BigDecimal::of($onHand));
            return [
                'id' => $line->id,
                'name' => $line->material_name,
                'unit' => $line->unit,
                'quantity' => $line->required_quantity,
                'packaging' => (bool) $line->is_packaging,
                'catalog_id' => $catalog?->id,
                'sku' => $catalog?->sku,
                'on_hand' => $onHand,
                'shortage' => $shortage !== null && $shortage->isGreaterThan(0)
                    ? (string) $shortage->toScale(6) : '0.000000',
            ];
        });
        $catalogMaterials = $request->user()->can('inventory.update')
            ? InventoryMaterial::where('is_active', true)->orderBy('name')->get()
            : collect();
        return view('production.order-show', compact('order', 'materials', 'catalogMaterials'));
    }

    public function linkMaterial(Request $request, ProductionOrder $order, ProductionOrderMaterial $materialLine) {
        abort_unless($request->user()->can('inventory.update'), 403);
        abort_unless($materialLine->production_order_id === $order->id, 404);
        $data = $request->validate([
            'inventory_material_id' => [
                'required',
                Rule::exists('inventory_materials', 'id')->where('is_active', true),
            ],
        ]);
        $catalog = InventoryMaterial::findOrFail($data['inventory_material_id']);
        if ($catalog->unit !== $materialLine->unit || $catalog->is_packaging !== (bool) $materialLine->is_packaging) {
            throw ValidationException::withMessages([
                'inventory_material_id' => 'Stock unit and material type must match the saved production requirement.',
            ]);
        }
        $materialLine->update(['inventory_material_id' => $catalog->id]);
        return back()->with('success', 'Material linked to stock catalog. No stock was reserved or consumed.');
    }
}
