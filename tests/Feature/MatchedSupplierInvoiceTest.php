<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\GoodsReceipt;
use App\Models\InventoryMaterial;
use App\Models\LedgerAccount;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\MatchedSupplierInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MatchedSupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        $currency = Currency::create([
            'code' => 'AFN', 'name' => 'Afghani',
            'is_base' => true, 'is_active' => true,
        ]);
        $supplier = TradeParty::create([
            'type' => 'supplier', 'name' => 'Invoice match supplier',
            'is_active' => true, 'created_by' => $user->id,
        ]);
        $supplier->currencies()->attach($currency->id);
        app(EntityAccountProvisioner::class)->provision(
            'supplier', $supplier->id, $supplier->name, $currency->id
        );
        $material = InventoryMaterial::create([
            'sku' => 'MATCH-RAW-001', 'name' => 'Matched flour', 'unit' => 'kg',
        ]);
        $order = PurchaseOrder::create([
            'number' => 'PO-MATCH-001', 'supplier_id' => $supplier->id,
            'currency_id' => $currency->id, 'source' => 'local',
            'order_date' => today()->subDays(3)->toDateString(),
            'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        $item = $order->items()->create([
            'description' => 'Matched flour', 'quantity' => '5',
            'unit_price' => '20', 'inventory_material_id' => $material->id,
        ]);
        $receipt = GoodsReceipt::create([
            'number' => 'GRN-MATCH-001', 'purchase_order_id' => $order->id,
            'receipt_token' => (string) Str::uuid(),
            'received_on' => today()->subDay()->toDateString(),
            'status' => 'posted', 'created_by' => $user->id,
        ]);
        $line = $receipt->lines()->create([
            'purchase_order_item_id' => $item->id,
            'inventory_material_id' => $material->id, 'quantity' => '5',
            'unit' => 'kg', 'unit_price_snapshot' => '20',
            'line_subtotal_snapshot' => '100',
        ]);
        return [$user, $receipt, $line, LedgerAccount::where('code', '1141')->firstOrFail()];
    }

    private function payload(int $lineId, int $inventoryLedgerId, string $quantity = '3', string $total = '60'): array
    {
        return [
            'submission_token' => (string) Str::uuid(),
            'supplier_invoice_number' => 'SUP-MATCH-001',
            'invoice_date' => today()->toDateString(),
            'due_date' => today()->addDays(15)->toDateString(),
            'inventory_ledger_id' => $inventoryLedgerId,
            'invoice_total' => $total,
            'quantities' => [$lineId => $quantity],
        ];
    }

    public function test_matching_posted_receipt_creates_one_payable_and_balanced_journal(): void
    {
        [$user, $receipt, $line, $inventory] = $this->fixture();
        $service = app(MatchedSupplierInvoiceService::class);
        $payload = $this->payload($line->id, $inventory->id);
        $invoice = $service->post($receipt, $payload, $user->id);

        $this->assertSame('posted', $invoice->status);
        $this->assertSame('60.0000', $invoice->total);
        $this->assertSame('posted', $invoice->journal->status);
        $this->assertEquals(60, $invoice->payable->amount);
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('2.000000', $service->remaining($receipt)[$line->id]);
        $this->assertEquals(60, $invoice->journal->lines->sum('debit'));
        $this->assertEquals(60, $invoice->journal->lines->sum('credit'));

        $replayed = $service->post($receipt, $payload, $user->id);
        $this->assertSame($invoice->id, $replayed->id);
        $this->assertDatabaseCount('matched_supplier_invoices', 1);
        $this->assertDatabaseCount('trade_documents', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_invoice_cannot_bill_more_than_remaining_receipt_quantity(): void
    {
        [$user, $receipt, $line, $inventory] = $this->fixture();
        $service = app(MatchedSupplierInvoiceService::class);
        $service->post($receipt, $this->payload($line->id, $inventory->id), $user->id);
        $overbill = $this->payload($line->id, $inventory->id, '3', '60');
        $overbill['supplier_invoice_number'] = 'SUP-MATCH-002';

        try {
            $service->post($receipt, $overbill, $user->id);
            $this->fail('Overbilling must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantities', $exception->errors());
        }

        $this->assertDatabaseCount('matched_supplier_invoices', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }
    public function test_incorrect_supplier_invoice_total_rolls_back_every_financial_record(): void
    {
        [$user, $receipt, $line, $inventory] = $this->fixture();
        $data = $this->payload($line->id, $inventory->id, '3', '61');

        try {
            app(MatchedSupplierInvoiceService::class)->post($receipt, $data, $user->id);
            $this->fail('A supplier invoice with an incorrect source total must not post.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_total', $exception->errors());
        }

        $this->assertDatabaseCount('matched_supplier_invoices', 0);
        $this->assertDatabaseCount('trade_documents', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertSame('5.000000', app(MatchedSupplierInvoiceService::class)->remaining($receipt)[$line->id]);
    }

    public function test_same_supplier_invoice_number_with_a_new_token_is_rejected(): void
    {
        [$user, $receipt, $line, $inventory] = $this->fixture();
        $service = app(MatchedSupplierInvoiceService::class);
        $service->post($receipt, $this->payload($line->id, $inventory->id), $user->id);
        $duplicate = $this->payload($line->id, $inventory->id, '1', '20');

        try {
            $service->post($receipt, $duplicate, $user->id);
            $this->fail('Duplicate supplier invoice references must not create additional payables.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_invoice_number', $exception->errors());
        }

        $this->assertDatabaseCount('matched_supplier_invoices', 1);
        $this->assertDatabaseCount('trade_documents', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('2.000000', $service->remaining($receipt)[$line->id]);
    }

    public function test_reused_submission_token_cannot_change_quantities_or_payment_terms(): void
    {
        [$user, $receipt, $line, $inventory] = $this->fixture();
        $service = app(MatchedSupplierInvoiceService::class);
        $original = $this->payload($line->id, $inventory->id);
        $service->post($receipt, $original, $user->id);

        $changedQuantity = $original;
        $changedQuantity['quantities'][$line->id] = '2';
        try {
            $service->post($receipt, $changedQuantity, $user->id);
            $this->fail('A token must not silently accept different quantities.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission_token', $exception->errors());
        }

        $changedDueDate = $original;
        $changedDueDate['due_date'] = today()->addDays(20)->toDateString();
        try {
            $service->post($receipt, $changedDueDate, $user->id);
            $this->fail('A token must not silently accept different terms.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission_token', $exception->errors());
        }

        $this->assertDatabaseCount('matched_supplier_invoices', 1);
        $this->assertDatabaseCount('trade_documents', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

}
