<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\FinishedGood;
use App\Models\SalesQuotation;
use App\Models\TradeParty;
use App\Services\SalesQuotationService;
use Illuminate\Http\Request;

class SalesQuotationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'reports.view']), 403);
        return view('sales.quotations.index', ['quotations' => SalesQuotation::with(['customer', 'currency'])->latest('id')->paginate(25)]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        return view('sales.quotations.create', [
            'customers' => TradeParty::with('currencies')->where('type', 'customer')->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
            'products' => FinishedGood::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SalesQuotationService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'customer_id' => ['required', 'integer', 'exists:trade_parties,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'quotation_date' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['required', 'date', 'after_or_equal:quotation_date'],
            'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.finished_good_id' => ['nullable', 'integer', 'exists:finished_goods,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'lines.*.unit' => ['required', 'string', 'max:40'],
            'lines.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
        ]);
        $quote = $service->create($data, (int) $request->user()->id);
        return redirect()->route('sales.quotations.show', $quote)->with('success', 'Quotation issued. It has not affected stock or accounts.');
    }

    public function show(Request $request, SalesQuotation $quotation)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'reports.view']), 403);
        $quotation->load(['customer', 'currency', 'lines']);
        return view('sales.quotations.show', ['quotation' => $quotation, 'company' => CompanySetting::first()]);
    }
}
