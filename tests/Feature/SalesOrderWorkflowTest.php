<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\SalesOrderService;
use App\Services\SalesQuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_quote_creates_one_order_and_immutable_lines_without_stock_or_posting(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_base' => true, 'is_active' => true]);
        $customer = TradeParty::create(['type' => 'customer', 'name' => 'Synthetic order customer', 'code' => 'CUS-ORDER-TEST', 'is_active' => true, 'created_by' => $user->id]);
        $customer->currencies()->attach($currency->id);
        $quote = app(SalesQuotationService::class)->create([
            'submission_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'currency_id' => $currency->id,
            'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => [['description' => 'Synthetic order item', 'quantity' => 3, 'unit' => 'bag', 'unit_price' => '12.5000']],
        ], $user->id);
        $service = app(SalesOrderService::class);
        $order = $service->acceptQuotation($quote->id, $user->id, (string) Str::uuid());
        $retry = $service->acceptQuotation($quote->id, $user->id, (string) Str::uuid());
        $this->assertSame($order->id, $retry->id);
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('37.5000', $order->total);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_order_lines', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('trade_documents', 0);
        $this->assertDatabaseCount('finished_good_dispatches', 0);
        Permission::findOrCreate('sales.view', 'web');
        $user->givePermissionTo('sales.view');
        $this->actingAs($user)->get(route('sales.orders.show', $order))->assertOk()->assertSee('No inventory reservation or dispatch has occurred');
    }

    public function test_expired_quotation_cannot_be_accepted(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_base' => true, 'is_active' => true]);
        $customer = TradeParty::create(['type' => 'customer', 'name' => 'Synthetic expired customer', 'code' => 'CUS-ORDER-EXPIRED', 'is_active' => true, 'created_by' => $user->id]);
        $customer->currencies()->attach($currency->id);
        $quote = app(SalesQuotationService::class)->create([
            'submission_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'currency_id' => $currency->id,
            'quotation_date' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDay()->toDateString(),
            'lines' => [['description' => 'Synthetic expired item', 'quantity' => 1, 'unit' => 'bag', 'unit_price' => '1.0000']],
        ], $user->id);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SalesOrderService::class)->acceptQuotation($quote->id, $user->id);
    }
}
