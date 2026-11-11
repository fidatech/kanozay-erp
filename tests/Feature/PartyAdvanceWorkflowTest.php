<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMaterial;
use App\Models\LedgerAccount;
use App\Models\PurchaseOrder;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Models\User;
use App\Services\Accounting\EntityAccountProvisioner;
use App\Services\MatchedSupplierInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\CapitalizesProductionBatches;
use Tests\TestCase;

/**
 * M18: customer deposits (receive, apply, refund) and supplier prepayments (pay, apply)
 * with their cash, advance and control-account effects in the General Ledger.
 */
class PartyAdvanceWorkflowTest extends TestCase
{
    use CapitalizesProductionBatches; // only for ledgerBalanceForTest()
    use RefreshDatabase;

    private User $user;
    private Currency $currency;
    private FinancialAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administration.view', 'administration.update', 'inventory.update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['administration.view', 'administration.update', 'inventory.update']);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $this->cash = FinancialAccount::create(['type' => 'cashbox', 'name' => 'Synthetic cash', 'currency_id' => $this->currency->id, 'is_active' => true, 'created_by' => $this->user->id]);
        app(EntityAccountProvisioner::class)->provision('cashbox', $this->cash->id, $this->cash->name, $this->currency->id);
    }

    private function day(int $ago): string
    {
        return today()->subDays($ago)->toDateString();
    }

    private function ledger(string $entityType, int $entityId, string $purpose): int
    {
        return (int) DB::table('entity_ledger_accounts')->where([
            'entity_type' => $entityType, 'entity_id' => $entityId,
            'currency_id' => $this->currency->id, 'purpose' => $purpose,
        ])->value('ledger_account_id');
    }

    private function balance(int $ledgerId): string
    {
        return $this->ledgerBalanceForTest($ledgerId, $this->currency->id);
    }

    private function customer(string $name): TradeParty
    {
        $party = TradeParty::create(['type' => 'customer', 'name' => $name, 'is_active' => true, 'created_by' => $this->user->id]);
        $party->currencies()->attach($this->currency->id);
        $party->provisionLedgerAccountsForCurrency($this->currency->id);

        return $party;
    }

    public function test_customer_deposit_is_a_liability_until_applied_and_only_the_unused_part_can_be_refunded(): void
    {
        $customer = $this->customer('Synthetic deposit customer');
        $income = LedgerAccount::where('code', '4110')->firstOrFail();
        $this->actingAs($this->user)->post(route('finance.documents.store'), [
            'submission_token' => (string) Str::uuid(), 'type' => 'receivable', 'trade_party_id' => $customer->id,
            'currency_id' => $this->currency->id, 'document_date' => $this->day(3), 'due_date' => today()->addDays(20)->toDateString(),
            'amount' => '300.00', 'counterpart_ledger_account_id' => $income->id, 'description' => 'Synthetic invoice',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = TradeDocument::firstOrFail();

        $cashLedger = $this->ledger('cashbox', $this->cash->id, 'cash');
        $advanceLedger = $this->ledger('customer', $customer->id, 'advance');
        $receivableLedger = $this->ledger('customer', $customer->id, 'receivable');
        $this->assertNotSame($advanceLedger, $receivableLedger);
        $this->assertSame('300.0000', $this->balance($receivableLedger));

        // Receive 200: cash up, liability up, receivable untouched. Replays do nothing.
        $deposit = ['submission_token' => (string) Str::uuid(), 'currency_id' => $this->currency->id,
            'destination_account_id' => $this->cash->id, 'received_on' => $this->day(5), 'amount' => '200.00', 'reference' => 'SYN-DEP-1'];
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->user)->post(route('finance.customers.advances.receive', $customer), $deposit)
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($this->user)->post(route('finance.customers.advances.receive', $customer), ['amount' => '250.00'] + $deposit)
            ->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('customer_advance_receipts', 1);
        $receiptId = (int) DB::table('customer_advance_receipts')->value('id');
        $this->assertSame('200.0000', $this->balance($cashLedger));
        $this->assertSame('-200.0000', $this->balance($advanceLedger));
        $this->assertSame('300.0000', $this->balance($receivableLedger));

        // Apply 120 on the invoice date itself.
        $apply = fn (string $amount, string $on, ?string $token = null) => $this->actingAs($this->user)->post(
            route('finance.customers.advances.apply', [$customer, $receiptId]),
            ['submission_token' => $token ?? (string) Str::uuid(), 'trade_document_id' => $invoice->id, 'applied_on' => $on, 'amount' => $amount]);
        $apply('120.00', $this->day(4))->assertSessionHasErrors('applied_on'); // before the invoice exists
        $token = (string) Str::uuid();
        $apply('120.00', $this->day(3), $token)->assertRedirect()->assertSessionHasNoErrors();
        $apply('120.00', $this->day(3), $token)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customer_advance_applications', 1);
        $this->assertSame('-80.0000', $this->balance($advanceLedger));
        $this->assertSame('180.0000', $this->balance($receivableLedger));
        $this->assertSame('200.0000', $this->balance($cashLedger));

        // Cannot apply or refund more than the 80 that is left.
        $apply('100.00', $this->day(2))->assertSessionHasErrors('amount');
        $refund = fn (string $amount, string $on) => $this->actingAs($this->user)->post(
            route('finance.customers.advances.refund', [$customer, $receiptId]),
            ['submission_token' => (string) Str::uuid(), 'source_account_id' => $this->cash->id, 'refunded_on' => $on, 'amount' => $amount, 'reference' => 'SYN-REF-1']);
        $refund('90.00', $this->day(1))->assertSessionHasErrors('amount');
        $refund('80.00', $this->day(1))->assertRedirect()->assertSessionHasNoErrors();
        $refund('1.00', $this->day(0))->assertSessionHasErrors('amount');
        $apply('1.00', $this->day(0))->assertSessionHasErrors('amount');

        $this->assertSame('0.0000', $this->balance($advanceLedger));
        $this->assertSame('120.0000', $this->balance($cashLedger));
        $this->assertSame('180.0000', $this->balance($receivableLedger));
        $this->assertDatabaseCount('customer_advance_refunds', 1);

        // Every journal is posted and balanced.
        foreach (DB::table('journal_entries')->get() as $journal) {
            $this->assertSame('posted', $journal->status);
            $lines = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->get();
            $this->assertEquals((float) $lines->sum('debit'), (float) $lines->sum('credit'));
        }

        // A deposit cannot be used through another customer's URL, or without permission.
        $other = $this->customer('Synthetic other customer');
        $this->actingAs($this->user)->post(route('finance.customers.advances.apply', [$other, $receiptId]), [])->assertNotFound();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->post(route('finance.customers.advances.receive', $customer), $deposit)->assertForbidden();
    }

    public function test_supplier_prepayment_is_an_asset_until_applied_to_the_matched_supplier_invoice(): void
    {
        $supplier = TradeParty::create(['type' => 'supplier', 'name' => 'Synthetic prepaid supplier', 'is_active' => true, 'created_by' => $this->user->id]);
        $supplier->currencies()->attach($this->currency->id);
        app(EntityAccountProvisioner::class)->provision('supplier', $supplier->id, $supplier->name, $this->currency->id);
        $material = InventoryMaterial::create(['sku' => 'ADV-RAW-01', 'name' => 'Synthetic cumin', 'unit' => 'kg']);
        $order = PurchaseOrder::create([
            'number' => 'PO-ADV-1', 'supplier_id' => $supplier->id, 'currency_id' => $this->currency->id, 'source' => 'local',
            'order_date' => $this->day(8), 'status' => 'confirmed', 'created_by' => $this->user->id,
        ]);
        $item = $order->items()->create(['description' => 'Cumin', 'quantity' => '10.0000', 'unit_price' => '20.0000', 'inventory_material_id' => $material->id]);

        $cashLedger = $this->ledger('cashbox', $this->cash->id, 'cash');
        $advanceLedger = $this->ledger('supplier', $supplier->id, 'advance');
        $payableLedger = $this->ledger('supplier', $supplier->id, 'payable');

        // Pay 150 before delivery. Replay is harmless; token reuse with other details is refused.
        $payment = ['purchase_order_id' => $order->id, 'submission_token' => (string) Str::uuid(),
            'source_account_id' => $this->cash->id, 'paid_on' => $this->day(6), 'amount' => '150.00', 'reference' => 'SYN-ADV-1'];
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->user)->post(route('supplier-advances.pay'), $payment)->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($this->user)->post(route('supplier-advances.pay'), ['amount' => '175.00'] + $payment)->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('supplier_advance_postings', 1);
        $advance = DB::table('supplier_advance_postings')->first();
        $this->assertSame('SAP-'.str_pad((string) $advance->id, 7, '0', STR_PAD_LEFT), $advance->number);
        $this->assertSame('150.0000', $this->balance($advanceLedger));
        $this->assertSame('-150.0000', $this->balance($cashLedger));
        $this->assertSame('0.0000', $this->balance($payableLedger));

        // Goods arrive and the supplier invoice creates the payable.
        $this->actingAs($this->user)->post(route('purchases.orders.receipts.store', $order), [
            'receipt_token' => (string) Str::uuid(), 'received_on' => $this->day(3), 'quantities' => [$item->id => '10.000000'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        app(MatchedSupplierInvoiceService::class)->post(GoodsReceipt::firstOrFail(), [
            'submission_token' => (string) Str::uuid(), 'supplier_invoice_number' => 'SYN-SUP-ADV-1',
            'invoice_date' => $this->day(2), 'due_date' => today()->addDays(20)->toDateString(),
            'inventory_ledger_id' => LedgerAccount::where('code', '1141')->firstOrFail()->id,
            'invoice_total' => '200', 'quantities' => [GoodsReceiptLine::firstOrFail()->id => '10'],
        ], $this->user->id);
        $payable = TradeDocument::where('type', 'payable')->firstOrFail();
        $this->assertSame('-200.0000', $this->balance($payableLedger));

        // A fully received order can no longer take a new advance.
        $this->actingAs($this->user)->post(route('supplier-advances.pay'), ['submission_token' => (string) Str::uuid()] + $payment)
            ->assertSessionHasErrors('purchase_order_id');

        $apply = fn (string $amount, string $on, ?string $token = null) => $this->actingAs($this->user)->post(
            route('supplier-advances.apply', $advance->id),
            ['submission_token' => $token ?? (string) Str::uuid(), 'trade_document_id' => $payable->id, 'applied_on' => $on,
                'amount' => $amount, 'approval_reference' => 'SYN-APPLY-1']);
        $apply('150.00', $this->day(3))->assertSessionHasErrors('applied_on'); // before the invoice date
        $apply('151.00', $this->day(2))->assertSessionHasErrors('amount');     // more than was prepaid
        $token = (string) Str::uuid();
        $apply('150.00', $this->day(2), $token)->assertRedirect()->assertSessionHasNoErrors();
        $apply('150.00', $this->day(2), $token)->assertRedirect()->assertSessionHasNoErrors();
        $apply('1.00', $this->day(1))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('supplier_advance_applications', 1);
        $this->assertDatabaseCount('trade_document_credits', 1);

        // Prepayment cleared; 50 still owed; no extra cash moved by the application.
        $this->assertSame('0.0000', $this->balance($advanceLedger));
        $this->assertSame('-50.0000', $this->balance($payableLedger));
        $this->assertSame('-150.0000', $this->balance($cashLedger));

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->post(route('supplier-advances.pay'), $payment)->assertForbidden();
        $this->actingAs($this->user)->get(route('workspace.page', ['purchases', 'supplier-advances']))->assertOk()->assertSee($advance->number);
    }
}
