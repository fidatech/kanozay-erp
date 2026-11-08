<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Models\SalesInvoice;
use App\Models\SalesOrderLine;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Support\LocalizedDecimal;
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
        $orderLines = SalesOrderLine::with(['order', 'finishedGood', 'invoiceLines'])
            ->whereNotNull('finished_good_id')->whereHas('order', fn ($query) => $query->where('status', 'confirmed'))->get()
            ->filter(fn ($line) => $line->quantity - $line->invoiceLines->sum('quantity_packages') > 0);

        return view('sales.invoices.create', [
            'customers' => TradeParty::with('currencies')->where('type', 'customer')->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
            'incomeAccounts' => LedgerAccount::where('type', 'income')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(),
            'batches' => $batches->filter(fn ($batch) => $batch->available_packages > 0),
            'orderLines' => $orderLines,
            'warehouses'=>\App\Models\CompanyWarehouse::where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SalesInvoiceService $invoices)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $lines=$request->input('lines');
        if(is_array($lines))foreach($lines as &$line){
            if(!is_array($line))continue;
            foreach(['quantity_packages','unit_price'] as $field){
                if(array_key_exists($field,$line))$line[$field]=LocalizedDecimal::normalize($line[$field]);
            }
        }
        unset($line);
        if(is_array($lines))$request->merge(['lines'=>$lines]);
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
            'lines.*.sales_order_line_id' => ['nullable', 'integer', 'exists:sales_order_lines,id'],
            'lines.*.production_batch_id' => ['required', 'integer', 'distinct', 'exists:production_batches,id'],
            'lines.*.company_warehouse_id'=>['nullable',\Illuminate\Validation\Rule::exists('company_warehouses','id')->where('is_active',true)],
            'lines.*.quantity_packages' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'lines.*.unit_price' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
        ]);

        $invoice = $invoices->post($data, (int) $request->user()->id);
        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Invoice posted, customer receivable recorded and selected batch stock dispatched.');
    }

    public function reconcileCOGS(Request $request,SalesInvoice $invoice)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        // Historic COGS postings now require a separately approved,
        // source-fingerprinted correction case with immutable event history.
        // The old endpoint is intentionally nonposting for existing clients.
        return redirect()->route('finance.historical-corrections.index',[
            'type'=>'invoice_missing_cogs','source_id'=>$invoice->id,
        ])->with('success',
            'Submit the source invoice as a historical correction case for independent finance review. The direct posting shortcut has been disabled.');
    }

    public function show(Request $request, SalesInvoice $invoice)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'finance.view', 'reports.view']), 403);
        $invoice->load(['customer', 'currency', 'lines.finishedGood', 'lines.batch', 'journal', 'tradeDocument.allocations', 'tradeDocument.credits', 'creditNotes.lines']);

        $paid = BigDecimal::zero();
        foreach ($invoice->tradeDocument?->allocations ?? [] as $allocation) $paid = $paid->plus((string) $allocation->amount);
        $credited = BigDecimal::zero();
        foreach ($invoice->tradeDocument?->credits ?? [] as $credit) $credited = $credited->plus((string) $credit->amount);
        $outstanding = BigDecimal::of((string) $invoice->total)->minus($paid)->minus($credited);
        if ($outstanding->isLessThan(0)) $outstanding = BigDecimal::zero();

        $prior = TradeDocument::with(['allocations','credits'])->where('type', 'receivable')
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
            $creditTotal = BigDecimal::zero();
            foreach ($document->credits as $credit) $creditTotal = $creditTotal->plus((string) $credit->amount);
            $balance = BigDecimal::of((string) $document->amount)->minus($allocated)->minus($creditTotal);
            if ($balance->isGreaterThan(0)) $previousBalance = $previousBalance->plus($balance);
        }

        $deliveryNotes=\App\Models\SalesDeliveryNote::with('lines')
            ->where('sales_invoice_id',$invoice->id)->orderByDesc('id')->get();
        $logistics=['documented'=>0,'delivered'=>0,'returned'=>0,'cancelled'=>0];
        foreach($deliveryNotes as $note){
            $packages=(int)$note->lines->sum('quantity_packages');
            if($note->status==='cancelled')$logistics['cancelled']+=$packages;
            else{
                $logistics['documented']+=$packages;
                if($note->status==='delivered')$logistics['delivered']+=$packages;
                if($note->status==='returned')$logistics['returned']+=$packages;
            }
        }

        $mayViewCosts=$request->user()->canAny([
            'finance.view','reports.view','administration.view',
        ]);
        $lineCosts=collect();
        $costTotals=[];
        if($mayViewCosts){
            $lineCosts=\Illuminate\Support\Facades\DB::table('production_invoice_cogs_postings as booked')
                ->join('production_batch_cost_postings as source',
                    'source.id','=','booked.production_batch_cost_posting_id')
                ->join('currencies as currency','currency.id','=','source.currency_id')
                ->join('journal_entries as journal','journal.id','=','booked.journal_entry_id')
                ->whereIn('booked.sales_invoice_line_id',$invoice->lines->pluck('id'))
                ->select('booked.*','currency.code as cost_currency_code',
                    'journal.number as journal_number','journal.status as journal_status')
                ->get()->keyBy('sales_invoice_line_id');
            foreach($lineCosts as $cost){
                $code=(string)$cost->cost_currency_code;
                $costTotals[$code]=isset($costTotals[$code])
                    ?$costTotals[$code]->plus((string)$cost->amount)
                    :BigDecimal::of((string)$cost->amount);
            }
            foreach($costTotals as $code=>$value){
                $costTotals[$code]=(string)$value->toScale(4);
            }
        }

        return view('sales.invoices.show', [
            'mayViewCosts'=>$mayViewCosts,
            'lineCosts'=>$lineCosts,
            'costTotals'=>$costTotals,
            'deliveryNotes'=>$deliveryNotes,
            'logistics'=>$logistics,
            'invoice' => $invoice,
            'company' => CompanySetting::first(),
            'paid' => (string) $paid->toScale(4),
            'credited' => (string) $credited->toScale(4),
            'outstanding' => (string) $outstanding->toScale(4),
            'previousBalance' => (string) $previousBalance->toScale(4),
        ]);
    }
}
