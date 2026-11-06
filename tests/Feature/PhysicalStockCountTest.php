<?php

namespace Tests\Feature;

use App\Models\InventoryMaterial;
use App\Models\InventoryStockCount;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhysicalStockCountTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        foreach (['inventory.view', 'inventory.create', 'inventory.update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['inventory.view', 'inventory.create', 'inventory.update']);

        return $user;
    }

    private function material(string $sku, int $quantity, User $actor): InventoryMaterial
    {
        $material = InventoryMaterial::create(['sku'=>$sku,'name'=>'Synthetic '.$sku,'unit'=>'kg']);
        if ($quantity > 0) {
            app(InventoryStockService::class)->post($material, [
                'type'=>'opening', 'direction'=>'in', 'quantity'=>(string)$quantity,
                'reference_type'=>'opening', 'reference_id'=>$material->id,
                'occurred_on'=>now()->toDateString(), 'created_by'=>$actor->id,
            ]);
        }
        return $material;
    }

    private function submission(array $quantities): array
    {
        return [
            'submission_token'=>(string)Str::uuid(),
            'counted_on'=>now()->toDateString(), 'notes'=>'Audited synthetic factory count',
            'quantities'=>$quantities,
        ];
    }

    public function test_draft_count_does_not_move_stock_and_approval_posts_differences_once(): void
    {
        $user=$this->operator();
        $a=$this->material('COUNT-1',10,$user);
        $b=$this->material('COUNT-2',5,$user);
        $payload=$this->submission([$a->id=>'8.000000',$b->id=>'7.000000']);
        $this->actingAs($user)->post(route('inventory.counts.store'),$payload)->assertRedirect();
        $count=InventoryStockCount::firstOrFail();
        $this->assertSame('draft',$count->status);
        $this->assertSame('10.000000',$a->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments',0);
        $this->actingAs($user)->get(route('inventory.counts.show',$count))->assertOk()->assertSee('COUNT-1');
        $this->actingAs($user)->post(route('inventory.counts.approve',$count))->assertRedirect();
        $this->assertSame('approved',$count->fresh()->status);
        $this->assertSame('8.000000',$a->fresh()->on_hand_quantity);
        $this->assertSame('7.000000',$b->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments',2);
        $this->actingAs($user)->post(route('inventory.counts.approve',$count))->assertSessionHasErrors('count');
        $this->assertDatabaseCount('inventory_stock_adjustments',2);
    }

    public function test_count_is_rejected_when_balance_changed_after_snapshot(): void
    {
        $user=$this->operator();
        $material=$this->material('COUNT-STALE',10,$user);
        $this->actingAs($user)->post(route('inventory.counts.store'),$this->submission([$material->id=>'7']))->assertRedirect();
        $count=InventoryStockCount::firstOrFail();
        app(InventoryStockService::class)->post($material,[
            'type'=>'adjustment_in','direction'=>'in','quantity'=>'1',
            'reference_type'=>'synthetic_external','reference_id'=>123,
            'occurred_on'=>now()->toDateString(),'created_by'=>$user->id,
        ]);
        $this->actingAs($user)->post(route('inventory.counts.approve',$count))->assertSessionHasErrors('count');
        $this->assertSame('draft',$count->fresh()->status);
        $this->assertSame('11.000000',$material->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments',0);
    }

    public function test_approval_is_atomic_when_one_line_would_breach_reservations(): void
    {
        $user=$this->operator();
        $a=$this->material('COUNT-SAFE',10,$user);
        $b=$this->material('COUNT-RESERVED',10,$user);
        $b->update(['reserved_quantity'=>'6.000000']);
        $this->actingAs($user)->post(route('inventory.counts.store'),$this->submission([
            $a->id=>'11',$b->id=>'5',
        ]))->assertRedirect();
        $count=InventoryStockCount::firstOrFail();
        $this->actingAs($user)->post(route('inventory.counts.approve',$count))->assertSessionHasErrors('quantity');
        $this->assertSame('draft',$count->fresh()->status);
        $this->assertSame('10.000000',$a->fresh()->on_hand_quantity);
        $this->assertSame('10.000000',$b->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_stock_adjustments',0);
    }

    public function test_duplicate_submission_and_unauthorized_actions_are_rejected(): void
    {
        $user=$this->operator();
        $material=$this->material('COUNT-ONLY',4,$user);
        $payload=$this->submission([$material->id=>'4']);
        $this->actingAs($user)->post(route('inventory.counts.store'),$payload)->assertRedirect();
        $this->actingAs($user)->post(route('inventory.counts.store'),$payload)->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('inventory_stock_counts',1);
        $outsider=User::factory()->create();
        $this->actingAs($outsider)->get(route('inventory.counts.index'))->assertForbidden();
        $this->actingAs($outsider)->post(route('inventory.counts.approve',InventoryStockCount::firstOrFail()))
            ->assertForbidden();
    }
}
