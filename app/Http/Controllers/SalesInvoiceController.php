<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Models\SalesInvoice;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\FinishedGoodDispatchService;
use App\Services\SalesInvoiceService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

class SalesInvoiceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'finance.view', 'reports.view']), 403);
        return view('sales.invoices.index', [
            'invoices' => SalesInvoice::with(['customer', 'currency'])->latest('id')->paginate(25),
        ]);
    }

    public function create(Request $request, FinishedGoodDispatchService $dispatches)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $batches = ProductionBatch::with(['finishedGood', 'order'])->where('status', 'released')->orderByDesc('released_at')->get();
        $batches->each(fn ($batch) => $batch->setAttribute('available_packages', $dispatches->availablePackages($batch)));

        return view('sales.invoices.create', [
            'customers' => TradeParty::with('currencies')->where('type', 'customer')->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
            'incomeAccounts' => LedgerAccount::where('type', 'income')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(),
            'batches' => $batches->filter(fn ($batch) => $batch->available_packages > 0),
        ]);
    }

    public function store(Request $request, SalesInvoiceService $invoices)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'customer_id' => ['required', 'integer', 'exists:trade_parties,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'income_ledger_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.dispatch_token' => ['required', 'uuid', 'distinct'],
            'lines.*.production_batch_id' => ['required', 'integer', 'distinct', 'exists:production_batches,id'],
            'lines.*.quantity_packages' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'lines.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
        ]);

        $invoice = $invoices->post($data, (int) $request->user()->id);
        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Invoice posted, customer receivable recorded and selected batch stock dispatched.');
    }

    public function show(Request $request, SalesInvoice $invoice)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'finance.view', 'reports.view']), 403);
        $invoice->load(['customer', 'currency', 'lines.finishedGood', 'lines.batch', 'journal', 'tradeDocument.allocations']);

        $paid = BigDecimal::zero();
        foreach ($invoice->tradeDocument?->allocations ?? [] as $allocation) $paid = $paid->plus((string) $allocation->amount);
        $outstanding = BigDecimal::of((string) $invoice->total)->minus($paid);
        if ($outstanding->isLessThan(0)) $outstanding = BigDecimal::zero();

        $prior = TradeDocument::with('allocations')->where('type', 'receivable')
            ->where('trade_party_id', $invoice->customer_id)->where('currency_id', $invoice->currency_id)
            ->where(function ($query) use ($invoice) {
                $query->whereDate('document_date', '<', $invoice->invoice_date->toDateString())
                    ->orWhere(function ($sameDay) use ($invoice) {
                        $sameDay->whereDate('document_date', $invoice->invoice_date->toDateString())
                            ->where('id', '<', $invoice->trade_document_id);
                    });
            })->get();
        $previousBalance = BigDecimal::zero();
        foreach ($prior as $document) {
            $allocated = BigDecimal::zero();
            foreach ($document->allocations as $allocation) $allocated = $allocated->plus((string) $allocation->amount);
            $balance = BigDecimal::of((string) $document->amount)->minus($allocated);
            if ($balance->isGreaterThan(0)) $previousBalance = $previousBalance->plus($balance);
        }

        return view('sales.invoices.show', [
            'invoice' => $invoice,
            'company' => CompanySetting::first(),
            'paid' => (string) $paid->toScale(4),
            'outstanding' => (string) $outstanding->toScale(4),
            'previousBalance' => (string) $previousBalance->toScale(4),
        ]);
    }
}
