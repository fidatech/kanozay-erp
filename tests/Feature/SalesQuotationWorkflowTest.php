<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\SalesQuotation;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\SalesQuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesQuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_issues_decimal_totals_and_does_not_post_financial_records(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_base' => true, 'is_active' => true]);
        $customer = TradeParty::create(['type' => 'customer', 'name' => 'Synthetic quote customer', 'code' => 'CUS-QUOTE-TEST', 'is_active' => true, 'created_by' => $user->id]);
        $customer->currencies()->attach($currency->id);
        $data = [
            'submission_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'currency_id' => $currency->id,
            'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(14)->toDateString(), 'reference' => 'QUOTE-TEST',
            'lines' => [
                ['description' => 'Synthetic product A', 'quantity' => 3, 'unit' => 'carton', 'unit_price' => '12.2500'],
                ['description' => 'Synthetic product B', 'quantity' => 2, 'unit' => 'piece', 'unit_price' => '5.5000'],
            ],
        ];
        $service = app(SalesQuotationService::class);
        $quote = $service->create($data, $user->id);
        $retry = $service->create($data, $user->id);
        $this->assertSame($quote->id, $retry->id);
        $this->assertSame('47.7500', $quote->fresh()->total);
        $this->assertSame('issued', $quote->status);
        $this->assertDatabaseCount('sales_quotations', 1);
        $this->assertDatabaseCount('sales_quotation_lines', 2);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('trade_documents', 0);
        $this->assertDatabaseCount('finished_good_dispatches', 0);

        Permission::findOrCreate('sales.view', 'web');
        $user->givePermissionTo('sales.view');
        $this->actingAs($user)->get(route('sales.quotations.show', $quote))->assertOk()->assertSee('Customer acceptance')->assertSee('does not reserve stock');
    }

    public function test_quotation_screen_requires_sales_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('sales.quotations.create'))->assertForbidden();
        $this->actingAs($user)->post(route('sales.quotations.store'), [])->assertForbidden();
    }
}
