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
    use \Tests\Support\CapitalizesProductionBatches;

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

        // Invoicing is blocked until the released batch is financially capitalized.
        try {
            app(SalesInvoiceService::class)->post([
                'submission_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'currency_id' => $currency->id,
                'income_ledger_id' => $income->id, 'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'lines' => [['dispatch_token' => (string) Str::uuid(), 'production_batch_id' => $batch->id,
                    'quantity_packages' => 1, 'unit_price' => '12.5000']],
            ], $user->id);
            $this->fail('An uncapitalized batch must not be invoiced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cost_of_goods_sold', $exception->errors());
        }
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('finished_good_dispatches', 0);
        $this->assertEquals(8, $good->fresh()->on_hand_packages);
        // Eight accepted packages carrying 80.0000 AFN of batch cost: 10.0000 per package.
        $cost = $this->capitalizeBatchForTest($batch, $currency, '80.0000', now()->toDateString());
        $finishedLedger = (int) $cost['policy']->finished_inventory_ledger_id;
        $cogsLedger = (int) $cost['policy']->cogs_ledger_id;
        $this->assertSame('80.0000', $this->ledgerBalanceForTest($finishedLedger, $currency->id));

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
        // COGS is posted once, in the batch cost currency, in the same transaction as the invoice.
        $this->assertDatabaseCount('production_invoice_cogs_postings', 1);
        $this->assertDatabaseHas('production_invoice_cogs_postings', [
            'sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity_packages' => 4, 'amount' => '40.0000',
        ]);
        $this->assertSame('40.0000', $this->ledgerBalanceForTest($finishedLedger, $currency->id));
        $this->assertSame('40.0000', $this->ledgerBalanceForTest($cogsLedger, $currency->id));
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
        // The whole batch is sold: finished-goods GL value is fully relieved to COGS.
        $this->assertSame('0.0000', $this->ledgerBalanceForTest($finishedLedger, $currency->id));
        $this->assertSame('80.0000', $this->ledgerBalanceForTest($cogsLedger, $currency->id));
        $this->assertEquals(0, $good->fresh()->on_hand_packages);

        // A credit note can never restock directly; returned goods enter QC quarantine.
        try {
            app(SalesCreditNoteService::class)->post($invoice->id, [
                'submission_token' => (string) Str::uuid(), 'credit_date' => now()->toDateString(),
                'reason' => 'Synthetic returned package', 'lines' => [[
                    'sales_invoice_line_id' => $invoice->lines->first()->id,
                    'quantity_packages' => 1, 'restock' => true,
                ]],
            ], $user->id);
            $this->fail('Direct restock from a credit note must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credit_note', $exception->errors());
        }
        $this->assertDatabaseCount('sales_credit_notes', 0);
        $credit = app(SalesCreditNoteService::class)->post($invoice->id, [
            'submission_token' => (string) Str::uuid(), 'credit_date' => now()->toDateString(),
            'reason' => 'Synthetic returned package', 'lines' => [[
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'quantity_packages' => 1, 'restock' => false,
            ]],
        ], $user->id);
        $this->assertSame('12.5000', $credit->total);
        // Credit alone restores neither saleable stock nor inventory value.
        $this->assertEquals(0, $good->fresh()->on_hand_packages);
        $this->assertSame(0, app(FinishedGoodDispatchService::class)->availablePackages($batch->fresh()));
        $this->assertSame('0.0000', $this->ledgerBalanceForTest($finishedLedger, $currency->id));
        $quarantine = \App\Models\SalesReturnQuarantine::firstOrFail();
        $this->assertSame('quarantined', $quarantine->status);
        // The person who registered the return cannot release it.
        try {
            app(\App\Services\SalesReturnQuarantineService::class)->decide($quarantine->id, [
                'action' => 'release', 'decided_on' => now()->toDateString(),
                'quality_reference' => 'QC-SELF', 'decision_notes' => 'Self release must be refused.',
            ], $user->id);
            $this->fail('Self-approved QC release must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('action', $exception->errors());
        }
        $inspector = User::factory()->create();
        app(\App\Services\SalesReturnQuarantineService::class)->decide($quarantine->id, [
            'action' => 'release', 'decided_on' => now()->toDateString(),
            'quality_reference' => 'QC-RETURN-INV-1', 'decision_notes' => 'Independent inspection passed.',
        ], $inspector->id);
        // Independent QC release restores one package and exactly its original COGS (10.0000).
        $this->assertDatabaseHas('production_return_cost_postings', [
            'sales_return_quarantine_id' => $quarantine->id, 'quantity_packages' => 1, 'amount' => '10.0000',
        ]);
        $this->assertSame('10.0000', $this->ledgerBalanceForTest($finishedLedger, $currency->id));
        $this->assertSame('70.0000', $this->ledgerBalanceForTest($cogsLedger, $currency->id));
        $this->assertEquals(1, $good->fresh()->on_hand_packages);
        $this->assertSame(1, app(FinishedGoodDispatchService::class)->availablePackages($batch->fresh()));
        $this->assertDatabaseHas('trade_document_credits', ['trade_document_id' => $invoice->trade_document_id, 'amount' => '12.5000']);
        $this->assertSame(0, \Brick\Math\BigDecimal::of((string) $credit->journal->lines()->where('debit', '>', 0)->value('debit'))->compareTo(\Brick\Math\BigDecimal::of('12.5000')));

        $invalid['lines'][0]['quantity_packages'] = 1;
        try {
            $service->post($invalid, $user->id);
            $this->fail('An invoice exceeding the remaining sales order quantity should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertDatabaseCount('sales_invoices', 2);
        // 3 fixture cost journals + 2 invoices + 2 COGS + 1 credit note + 1 QC return recovery.
        $this->assertSame(3, \App\Models\JournalEntry::where('number', 'like', 'FGC-%')->where('status', 'posted')->count());
        $this->assertDatabaseCount('journal_entries', 9);
        $this->assertSame(0, \App\Models\JournalEntry::where('status', '!=', 'posted')->count());
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
