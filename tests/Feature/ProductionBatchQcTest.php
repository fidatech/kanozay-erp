<?php

namespace Tests\Feature;

use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionBatchQcTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $laboratory;
    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manufacturing.update', 'laboratory.create', 'laboratory.update', 'inventory.update', 'inventory.create', 'inventory.view', 'administration.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['manufacturing.update', 'administration.view']);
        $this->laboratory = User::factory()->create();
        $this->laboratory->givePermissionTo(['laboratory.create', 'laboratory.update', 'administration.view']);
        $this->warehouse = User::factory()->create();
        $this->warehouse->givePermissionTo(['inventory.update', 'inventory.create', 'inventory.view', 'administration.view']);
    }

    public function test_accepted_packages_only_enter_stock_after_lab_approval_and_warehouse_release(): void
    {
        [$order, $good] = $this->setupOrder();
        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $good->id, 'reported_packages' => 17,
        ])->assertRedirect();
        $batch = ProductionBatch::firstOrFail();
        $this->assertSame('pending_qc', $order->fresh()->status);
        $this->assertSame('0', (string) $good->fresh()->on_hand_packages);
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), [
            'test_name' => 'Moisture', 'specification' => 'Within limit',
            'observation' => 'Acceptable', 'result' => 'pass',
        ])->assertRedirect();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), [
            'decision' => 'approve', 'rejected_packages' => 2, 'qc_notes' => 'Two damaged bags.',
        ])->assertRedirect();
        $this->assertSame('qc_approved', $batch->fresh()->status);
        $this->assertSame(15, $batch->fresh()->accepted_packages);
        $this->assertSame(2, $batch->fresh()->rejected_packages);
        $this->assertDatabaseCount('finished_good_movements', 0);
        $this->assertEquals(0, $good->fresh()->on_hand_packages);

        $this->actingAs($this->warehouse)->post(route('production.orders.batches.release', [$order, $batch]), [
            'occurred_on' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('released', $batch->fresh()->status);
        $this->assertEquals(15, $good->fresh()->on_hand_packages);
        $this->assertDatabaseHas('finished_good_movements', [
            'production_batch_id' => $batch->id, 'quantity_packages' => 15,
            'type' => 'production_receipt',
        ]);
        $this->actingAs($this->warehouse)->post(route('production.orders.batches.release', [$order, $batch]), [
            'occurred_on' => now()->toDateString(),
        ])->assertSessionHasErrors('production_batch');
        $this->assertDatabaseCount('finished_good_movements', 1);
        $this->assertEquals(15, $good->fresh()->on_hand_packages);
    }

    public function test_failed_lab_check_prevents_approval_and_rejection_does_not_add_stock(): void
    {
        [$order, $good] = $this->setupOrder();
        $batch = $this->submitBatch($order, $good, 8);
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), [
            'test_name' => 'Microbiology', 'result' => 'fail', 'observation' => 'Above limit',
        ])->assertRedirect();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), [
            'decision' => 'approve', 'rejected_packages' => 0, 'qc_notes' => 'Not acceptable',
        ])->assertSessionHasErrors('production_batch');
        $this->assertSame('pending_qc', $batch->fresh()->status);
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), [
            'decision' => 'reject', 'rejected_packages' => 8, 'qc_notes' => 'Failed microbiology check',
        ])->assertRedirect();
        $this->assertSame('qc_rejected', $order->fresh()->status);
        $this->assertSame(0, $batch->fresh()->accepted_packages);
        $this->actingAs($this->warehouse)->post(route('production.orders.batches.release', [$order, $batch]), [
            'occurred_on' => now()->toDateString(),
        ])->assertSessionHasErrors('production_batch');
        $this->assertDatabaseCount('finished_good_movements', 0);
        $this->assertEquals(0, $good->fresh()->on_hand_packages);
    }

    public function test_qc_requires_checks_and_laboratory_checks_are_unique_and_frozen_after_decision(): void
    {
        [$order, $good] = $this->setupOrder();
        $batch = $this->submitBatch($order, $good, 5);
        $decision = ['decision' => 'approve', 'rejected_packages' => 0, 'qc_notes' => 'Passed'];
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), $decision)
            ->assertSessionHasErrors('production_batch');
        $test = ['test_name' => 'Appearance', 'result' => 'pass'];
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), $test)
            ->assertRedirect();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), $test)
            ->assertSessionHasErrors('production_batch');
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.decide', [$order, $batch]), $decision)
            ->assertRedirect();
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$order, $batch]), [
            'test_name' => 'Late test', 'result' => 'pass',
        ])->assertSessionHasErrors('production_batch');
        $this->assertDatabaseCount('production_batch_checks', 1);
    }

    public function test_invalid_finished_good_and_overproduction_are_rejected(): void
    {
        [$order, $good] = $this->setupOrder();
        $wrong = FinishedGood::create([
            'sku' => 'OTHER', 'name' => 'Different item', 'package_unit' => 'bag', 'package_weight_kg' => '5.000',
        ]);
        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $wrong->id, 'reported_packages' => 10,
        ])->assertSessionHasErrors('production_batch');
        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $good->id, 'reported_packages' => 21,
        ])->assertSessionHasErrors('production_batch');
        $this->assertDatabaseCount('production_batches', 0);
        $this->assertSame('issued', $order->fresh()->status);
    }

    public function test_permissions_and_cross_order_batch_scoping_are_enforced(): void
    {
        [$order, $good] = $this->setupOrder();
        $batch = $this->submitBatch($order, $good, 6);
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->post(route('production.orders.batches.checks.store', [$order, $batch]), [
            'test_name' => 'Appearance', 'result' => 'pass',
        ])->assertForbidden();
        $this->actingAs($this->operator)->post(route('production.orders.batches.release', [$order, $batch]), [
            'occurred_on' => now()->toDateString(),
        ])->assertForbidden();
        $other = ProductionOrder::create([
            'number' => 'PRD-000002', 'production_recipe_id' => $order->production_recipe_id,
            'package_count' => 5, 'status' => 'issued',
        ]);
        $this->actingAs($this->laboratory)->post(route('production.orders.batches.checks.store', [$other, $batch]), [
            'test_name' => 'Appearance', 'result' => 'pass',
        ])->assertNotFound();
        $this->assertDatabaseCount('production_batch_checks', 0);
    }

    private function setupOrder(): array
    {
        $recipe = ProductionRecipe::create([
            'name' => 'Five kilo bags', 'product_code' => 'FG-001', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-000001', 'production_recipe_id' => $recipe->id,
            'package_count' => 20, 'status' => 'issued',
        ]);
        $good = FinishedGood::create([
            'sku' => 'FG-001', 'name' => 'Five kilo bags', 'package_unit' => 'bag', 'package_weight_kg' => '5.000',
        ]);
        return [$order, $good];
    }

    private function submitBatch(ProductionOrder $order, FinishedGood $good, int $quantity): ProductionBatch
    {
        $this->actingAs($this->operator)->post(route('production.orders.batches.store', $order), [
            'finished_good_id' => $good->id, 'reported_packages' => $quantity,
        ])->assertRedirect();
        return ProductionBatch::firstOrFail();
    }
}
