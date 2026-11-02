<?php

namespace Tests\Feature;

use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use App\Services\FinishedGoodDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinishedGoodDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_reduces_warehouse_and_batch_balances_once(): void
    {
        [$good, $batch, $user] = $this->releasedBatch();
        $service = app(FinishedGoodDispatchService::class);
        $data = ['submission_token' => (string) Str::uuid(), 'dispatch_date' => '2026-11-02'];
        $dispatch = $service->post($good->id, $batch->id, 4, $data, $user->id);
        $duplicate = $service->post($good->id, $batch->id, 4, $data, $user->id);

        $this->assertSame($dispatch->id, $duplicate->id);
        $this->assertEquals(6, $good->fresh()->on_hand_packages);
        $this->assertSame(6, $service->availablePackages($batch->fresh()));
        $this->assertDatabaseCount('finished_good_dispatches', 1);
        $this->assertDatabaseCount('finished_good_dispatch_lines', 1);
    }

    public function test_dispatch_cannot_exceed_batch_balance_or_warehouse_balance(): void
    {
        [$good, $batch, $user] = $this->releasedBatch();
        try {
            app(FinishedGoodDispatchService::class)->post($good->id, $batch->id, 11, [
                'submission_token' => (string) Str::uuid(), 'dispatch_date' => '2026-11-02',
            ], $user->id);
            $this->fail('Over dispatch should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }
        $this->assertEquals(10, $good->fresh()->on_hand_packages);
        $this->assertDatabaseCount('finished_good_dispatches', 0);
    }

    public function test_dispatch_route_requires_inventory_update_permission(): void
    {
        [$good, $batch, $user] = $this->releasedBatch();
        Permission::findOrCreate('inventory.update', 'web');
        $this->actingAs($user)->post(route('production.finished-goods.dispatches.store', $good), [
            'submission_token' => (string) Str::uuid(), 'production_batch_id' => $batch->id,
            'quantity_packages' => 1, 'dispatch_date' => '2026-11-02',
        ])->assertForbidden();
        $user->givePermissionTo('inventory.update');
        $this->actingAs($user)->post(route('production.finished-goods.dispatches.store', $good), [
            'submission_token' => (string) Str::uuid(), 'production_batch_id' => $batch->id,
            'quantity_packages' => 1, 'dispatch_date' => '2026-11-02',
        ])->assertRedirect(route('production.finished-goods.show', $good));
        $this->assertEquals(9, $good->fresh()->on_hand_packages);
    }

    private function releasedBatch(): array
    {
        $user = User::factory()->create();
        $recipe = ProductionRecipe::create([
            'name' => 'Dispatch test recipe', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-DISPATCH-001', 'production_recipe_id' => $recipe->id,
            'package_count' => 10, 'status' => 'completed',
        ]);
        $good = FinishedGood::create([
            'sku' => 'FG-DISPATCH-001', 'name' => 'Synthetic product',
            'package_unit' => 'bag', 'package_weight_kg' => '5.000',
        ]);
        $good->forceFill(['on_hand_packages' => 10])->save();
        $batch = ProductionBatch::create([
            'production_order_id' => $order->id, 'finished_good_id' => $good->id,
            'batch_number' => 'BAT-DISPATCH-001', 'reported_packages' => 10,
        ]);
        $batch->forceFill(['accepted_packages' => 10, 'status' => 'released', 'released_by' => $user->id])->save();
        $good->movements()->create([
            'production_batch_id' => $batch->id, 'type' => 'production_receipt',
            'quantity_packages' => 10, 'occurred_on' => '2026-11-02', 'created_by' => $user->id,
        ]);

        return [$good, $batch, $user];
    }
}

