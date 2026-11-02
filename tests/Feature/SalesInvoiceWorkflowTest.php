<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\SalesInvoiceService;
use App\Services\SalesCreditNoteService;
use App\Services\FinishedGoodDispatchService;
use App\Services\SalesOrderService;
use App\Services\SalesQuotationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_posts_balanced_receivable_and_dispatches_batch_once(): void
    {
        app(ChartOfAccountsSeeder::class)->run();
        $user = User::factory()->create();
        $this->actingAs($user);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $customer = TradeParty::create([
            'type' => 'customer', 'name' => 'Synthetic test customer', 'code' => 'CUS-SALES-TEST',
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $customer->currencies()->attach($currency->id);
        $accounts = app(EntityAccountProvisioner::class)->provision('customer', $customer->id, $customer->name, $currency->id);
        $income = \App\Models\LedgerAccount::where('code', '4110')->firstOrFail();

        $recipe = ProductionRecipe::create([
            'name' => 'Invoice test recipe', 'method' => 'powder_blending',
            'package_weight_kg' => 5, 'package_unit' => 'bag', 'status' => 'approved',
        ]);
        $order = ProductionOrder::create([
            'number' => 'PRD-SALES-TEST', 'production_recipe_id' => $recipe->id,
            'package_count' => 8, 'status' => 'completed',
        ]);
        $good = FinishedGood::create([
            'sku' => 'FG-SALES-TEST', 'name' => 'Synthetic finished product',
            'package_unit' => 'bag', 'package_weight_kg' => '5.000',
        ]);
        $good->forceFill(['on_hand_packages' => 8])->save();
        $batch = ProductionBatch::create([
            'production_order_id' => $order->id, 'finished_good_id' => $good->id,
            'batch_number' => 'BAT-SALES-TEST', 'reported_packages' => 8,
        ]);
        $batch->forceFill(['accepted_packages' => 8, 'status' => 'released', 'released_by' => $user->id, 'released_at' => now()])->save();
        $good->movements()->create([
            'production_batch_id' => $batch->id, 'type' => 'production_receipt',
            'quantity_packages' => 8, 'occurred_on' => now()->toDateString(), 'created_by' => $user->id,
        ]);

        $quotation = app(SalesQuotationService::class)->create([
            'submission_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'currency_id' => $currency->id,
            'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => [['finished_good_id' => $good->id, 'description' => $good->name, 'quantity' => 8, 'unit' => 'bag', 'unit_price' => '12.5000']],
        ], $user->id);
        $salesOrder = app(SalesOrderService::class)->acceptQuotation($quotation->id, $user->id);
        $salesOrderLine = $salesOrder->lines->firstOrFail();

        $data = [
            'submission_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'income_ledger_id' => $income->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'reference' => 'TEST-ORDER-1',
            'lines' => [[
                'dispatch_token' => (string) Str::uuid(),
                'sales_order_line_id' => $salesOrderLine->id,
                'production_batch_id' => $batch->id,
                'quantity_packages' => 4,
                'unit_price' => '12.5000',
            ]],
        ];
        $service = app(SalesInvoiceService::class);
        $invoice = $service->post($data, $user->id);
        $duplicate = $service->post($data, $user->id);

        $this->assertSame($invoice->id, $duplicate->id);
        $this->assertSame('posted', $invoice->fresh()->status);
        $this->assertSame('posted', $invoice->journal->fresh()->status);
        $this->assertSame('50.0000', $invoice->fresh()->total);
        $this->assertEquals(4, $good->fresh()->on_hand_packages);
        $this->assertDatabaseHas('trade_documents', [
            'number' => $invoice->number, 'trade_party_id' => $customer->id,
            'amount' => '50.0000', 'journal_entry_id' => $invoice->journal_entry_id,
        ]);
        $debits = \App\Models\JournalLine::where('journal_entry_id', $invoice->journal_entry_id)->sum('debit');
        $credits = \App\Models\JournalLine::where('journal_entry_id', $invoice->journal_entry_id)->sum('credit');
        $this->assertEquals('50.0000', $debits);
        $this->assertEquals('50.0000', $credits);
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertDatabaseCount('finished_good_dispatches', 1);
        $this->assertDatabaseCount('sales_invoice_lines', 1);
        $this->assertNotEmpty($accounts['receivable']->id);
        Permission::findOrCreate('sales.view', 'web');
        $user->givePermissionTo('sales.view');
        $this->get(route('sales.invoices.show', $invoice))->assertOk()
            ->assertSee('Previous balance')->assertSee('Payments received')
            ->assertSee('Customer signature')->assertSee('Print / Save PDF');

        $invalid = $data;
        $invalid['submission_token'] = (string) Str::uuid();
        $invalid['lines'][0]['dispatch_token'] = (string) Str::uuid();
        $partial = $data;
        $partial['submission_token'] = (string) Str::uuid();
        $partial['lines'][0]['dispatch_token'] = (string) Str::uuid();
        $partial['lines'][0]['quantity_packages'] = 4;
        $secondInvoice = $service->post($partial, $user->id);
        $this->assertSame('50.0000', $secondInvoice->total);

        $credit = app(SalesCreditNoteService::class)->post($invoice->id, [
            'submission_token' => (string) Str::uuid(), 'credit_date' => now()->toDateString(),
            'reason' => 'Synthetic returned package', 'lines' => [[
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'quantity_packages' => 1, 'restock' => true,
            ]],
        ], $user->id);
        $this->assertSame('12.5000', $credit->total);
        $this->assertEquals(1, $good->fresh()->on_hand_packages);
        $this->assertSame(1, app(FinishedGoodDispatchService::class)->availablePackages($batch->fresh()));
        $this->assertDatabaseHas('trade_document_credits', ['trade_document_id' => $invoice->trade_document_id, 'amount' => '12.5000']);
        $this->assertSame('12.5000', (string) $credit->journal->lines()->where('debit', '>', 0)->value('debit'));

        $invalid['lines'][0]['quantity_packages'] = 1;
        try {
            $service->post($invalid, $user->id);
            $this->fail('An invoice exceeding the remaining sales order quantity should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertDatabaseCount('sales_invoices', 2);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('finished_good_dispatches', 2);
        $this->assertEquals(1, $good->fresh()->on_hand_packages);
        $this->assertEquals(8, \App\Models\SalesInvoiceLine::where('sales_order_line_id', $salesOrderLine->id)->sum('quantity_packages'));
    }

    public function test_invoice_creation_requires_sales_create_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('sales.invoices.create'))->assertForbidden();
        $this->actingAs($user)->post(route('sales.invoices.store'), [])->assertForbidden();
    }
}
