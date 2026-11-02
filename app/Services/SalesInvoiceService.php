<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\SalesInvoice;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesInvoiceService
{
    public function __construct(
        private readonly FinishedGoodDispatchService $dispatches,
        private readonly JournalPostingService $journals,
    ) {}

    public function post(array $data, int $actorId): SalesInvoice
    {
        return DB::transaction(function () use ($data, $actorId) {
            $existing = SalesInvoice::where('submission_token', $data['submission_token'])->first();
            if ($existing) return $existing->load(['customer', 'currency', 'lines.finishedGood', 'lines.batch', 'journal', 'tradeDocument']);

            $customer = TradeParty::query()->lockForUpdate()->findOrFail($data['customer_id']);
            $currency = Currency::whereKey($data['currency_id'])->where('is_active', true)->firstOrFail();
            if (! $customer->is_active || $customer->type !== 'customer'
                || ! $customer->currencies()->whereKey($currency->id)->exists()) {
                $this->reject('Choose an active customer enabled for the selected invoice currency.');
            }

            $income = LedgerAccount::query()->whereKey($data['income_ledger_id'])->lockForUpdate()->firstOrFail();
            if (! $income->is_active || $income->is_group || $income->type !== 'income') {
                $this->reject('Select an active revenue posting account.');
            }
            $customerLedger = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $customer->id,
                'currency_id' => $currency->id, 'purpose' => 'receivable',
            ])->value('ledger_account_id');
            if (! $customerLedger) $this->reject('The customer receivable ledger is not configured for this currency.');

            $subtotal = BigDecimal::zero();
            $calculated = [];
            foreach ($data['lines'] as $line) {
                $lineTotal = BigDecimal::of((string) $line['unit_price'])
                    ->multipliedBy((string) $line['quantity_packages'])->toScale(4, RoundingMode::HalfUp);
                $subtotal = $subtotal->plus($lineTotal);
                if ($lineTotal->isGreaterThan(BigDecimal::of('99999999999999999999.9999'))) {
                    $this->reject('An invoice line exceeds the supported monetary range.');
                }
                $calculated[] = ['line' => $line, 'total' => $lineTotal];
            }
            if ($subtotal->isGreaterThan(BigDecimal::of('99999999999999999999.9999'))) {
                $this->reject('Invoice total exceeds the supported monetary range.');
            }
            if (! $subtotal->isGreaterThan(0)) $this->reject('Invoice total must be greater than zero.');

            DocumentSequence::firstOrCreate(['document_type' => 'sales_invoice'], [
                'prefix' => 'INV-', 'next_number' => 1, 'padding' => 6, 'is_active' => true,
            ]);
            $number = DocumentSequence::issue('sales_invoice');
            $invoice = SalesInvoice::create([
                'number' => $number,
                'submission_token' => $data['submission_token'],
                'customer_id' => $customer->id,
                'currency_id' => $currency->id,
                'currency_code' => $currency->code,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'status' => 'draft',
                'subtotal' => (string) $subtotal->toScale(4, RoundingMode::HalfUp),
                'total' => (string) $subtotal->toScale(4, RoundingMode::HalfUp),
                'created_by' => $actorId,
            ]);

            foreach ($calculated as $item) {
                $line = $item['line'];
                $batch = \App\Models\ProductionBatch::findOrFail($line['production_batch_id']);
                $orderLineId = isset($line['sales_order_line_id']) && $line['sales_order_line_id'] !== '' ? (int) $line['sales_order_line_id'] : null;
                if ($orderLineId) {
                    $orderLine = \App\Models\SalesOrderLine::query()->lockForUpdate()->with('order')->findOrFail($orderLineId);
                    if ($orderLine->order->status !== 'confirmed'
                        || (int) $orderLine->order->customer_id !== (int) $customer->id
                        || (int) $orderLine->order->currency_id !== (int) $currency->id
                        || (int) $orderLine->finished_good_id !== (int) $batch->finished_good_id) {
                        $this->reject('The selected order line must match this customer, currency and finished product.');
                    }
                    $alreadyInvoiced = DB::table('sales_invoice_lines')->where('sales_order_line_id', $orderLineId)->sum('quantity_packages');
                    if ((int) $alreadyInvoiced + (int) $line['quantity_packages'] > (int) $orderLine->quantity) {
                        $this->reject('Invoice quantity exceeds the remaining quantity on the selected sales order line.');
                    }
                }
                $dispatch = $this->dispatches->post(
                    (int) $batch->finished_good_id,
                    (int) $batch->id,
                    (int) $line['quantity_packages'],
                    [
                        'submission_token' => $line['dispatch_token'],
                        'dispatch_date' => $data['invoice_date'],
                        'reference' => $number,
                        'notes' => 'Dispatch for sales invoice '.$number,
                    ],
                    $actorId,
                );
                $invoice->lines()->create([
                    'sales_order_line_id' => $orderLineId,
                    'finished_good_id' => $batch->finished_good_id,
                    'production_batch_id' => $batch->id,
                    'dispatch_id' => $dispatch->id,
                    'quantity_packages' => (int) $line['quantity_packages'],
                    'unit_price' => (string) BigDecimal::of((string) $line['unit_price'])->toScale(4, RoundingMode::HalfUp),
                    'line_total' => (string) $item['total']->toScale(4, RoundingMode::HalfUp),
                ]);
            }

            $journal = JournalEntry::create([
                'number' => $number,
                'entry_date' => $data['invoice_date'],
                'reference' => $number,
                'description' => 'Sales invoice '.$number.' — '.$customer->name,
                'currency_id' => $currency->id,
                'created_by' => $actorId,
                'status' => 'draft',
            ]);
            $journal->lines()->create(['ledger_account_id' => $customerLedger, 'debit' => (string) $subtotal, 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $income->id, 'debit' => '0', 'credit' => (string) $subtotal]);
            $this->journals->post($journal, $actorId);

            $document = TradeDocument::create([
                'type' => 'receivable', 'number' => $number,
                'trade_party_id' => $customer->id, 'currency_id' => $currency->id,
                'document_date' => $data['invoice_date'], 'due_date' => $data['due_date'],
                'amount' => (string) $subtotal, 'reference' => $data['reference'] ?? null,
                'description' => 'Sales invoice '.$number, 'journal_entry_id' => $journal->id,
                'created_by' => $actorId,
            ]);
            $invoice->forceFill([
                'status' => 'posted', 'journal_entry_id' => $journal->id,
                'trade_document_id' => $document->id,
            ])->save();
            ActivityLog::record('sales_invoice.posted', 'sales_invoice', $invoice->id, $number.' posted for '.$customer->name);

            return $invoice->load(['customer', 'currency', 'lines.finishedGood', 'lines.batch', 'journal', 'tradeDocument']);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
