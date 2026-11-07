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
        $this->assertSame('60.0000', $invoice->payable->amount);
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
}
