<?php

namespace App\Http\Controllers;

use App\Services\HistoricalFinanceCorrectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HistoricalFinanceCorrectionController extends Controller
{
    private function gate(Request $request,bool $write=false):void
    {
        abort_unless($request->user()->canAny($write
            ?['finance.update','administration.update']
            :['finance.view','finance.update','administration.view','reports.view']),403);
    }

    public function index(Request $request)
    {
        $this->gate($request);
        $filters=$request->validate([
            'status'=>['nullable',Rule::in(['submitted','approved','rejected','resolved','withdrawn'])],
            'type'=>['nullable',Rule::in(array_keys(HistoricalFinanceCorrectionService::TYPES))],
            'source_id'=>['nullable','integer','min:1'],
        ]);
        $cases=DB::table('finance_historical_correction_cases')
            ->when($filters['status']??null,fn($q,$value)=>$q->where('status',$value))
            ->when($filters['type']??null,fn($q,$value)=>$q->where('case_type',$value))
            ->when($filters['source_id']??null,fn($q,$value)=>$q->where('source_id',$value))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        $openCogs=DB::table('sales_invoice_lines as sale')
            ->join('sales_invoices as invoice',
                'invoice.id','=','sale.sales_invoice_id')
            ->leftJoin('production_invoice_cogs_postings as posted',
                'posted.sales_invoice_line_id','=','sale.id')
            ->where('invoice.status','posted')->whereNull('posted.id')
            ->distinct()->orderByDesc('sale.sales_invoice_id')
            ->limit(20)->pluck('sale.sales_invoice_id');
        $openBatches=DB::table('production_batches as batch')
            ->leftJoin('production_batch_cost_postings as posted',
                'posted.production_batch_id','=','batch.id')
            ->where('batch.status','released')->whereNull('posted.id')
            ->orderByDesc('batch.id')->limit(20)->pluck('batch.id');
        $orphanDispatches=DB::table('finished_good_dispatches as dispatch')
            ->join('finished_good_dispatch_lines as detail',
                'detail.finished_good_dispatch_id','=','dispatch.id')
            ->leftJoin('sales_invoice_lines as sale',
                'sale.dispatch_id','=','dispatch.id')
            ->whereNull('sale.id')->distinct()
            ->orderByDesc('dispatch.id')->limit(20)->pluck('dispatch.id');
        $unvaluedFifo=DB::table('inventory_cost_layers')
            ->whereNull('original_cost')->where('remaining_quantity','>',0)
            ->orderByDesc('id')->limit(20)->pluck('id');
        $unreconciledLanded=DB::table('goods_receipt_landed_costs as cost')
            ->leftJoin('goods_receipt_landed_cost_postings as posted',
                'posted.goods_receipt_landed_cost_id','=','cost.id')
            ->whereNull('posted.id')->orderByDesc('cost.id')
            ->limit(20)->pluck('cost.id');
        $pendingSupplierCredits=DB::table('purchase_return_records')
            ->whereIn('financial_status',
                ['supplier_credit_pending','landed_cost_review_pending'])
            ->orderByDesc('id')->limit(20)->pluck('id');

        return view('finance.historical-corrections',[
            'cases'=>$cases,'filters'=>$filters,
            'types'=>HistoricalFinanceCorrectionService::TYPES,
            'candidates'=>[
                'invoice_missing_cogs'=>$openCogs,
                'batch_uncapitalized'=>$openBatches,
                'standalone_dispatch'=>$orphanDispatches,
                'opening_fifo_unvalued'=>$unvaluedFifo,
                'landed_cost_unreconciled'=>$unreconciledLanded,
                'supplier_return_unreconciled'=>$pendingSupplierCredits,
            ],
        ]);
    }

    public function store(Request $request,HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'case_type'=>['required',Rule::in(array_keys(HistoricalFinanceCorrectionService::TYPES))],
            'source_id'=>['required','integer','min:1'],
            'identified_discrepancy'=>['required','string','min:20','max:4000'],
            'source_evidence'=>['required','string','min:20','max:5000'],
            'confirm_original_source'=>['accepted'],
        ]);
        $case=$service->submit($data,(int)$request->user()->id);
        return redirect()->route('finance.historical-corrections.show',$case->id)
            ->with('success','Historical source discrepancy submitted. A different authorized finance reviewer must approve it before any COGS posting.');
    }

    public function show(Request $request,int $case,HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request);
        $row=DB::table('finance_historical_correction_cases')->where('id',$case)->first();
        abort_unless($row,404);
        $openingEquityLedgers=collect();
        $currencies=collect();
        $openingPosting=null;
        if($row->case_type==='opening_fifo_unvalued'){
            $openingPosting=DB::table('finance_opening_fifo_valuations as source')
                ->join('journal_entries as journal',
                    'journal.id','=','source.journal_entry_id')
                ->where('source.finance_historical_correction_case_id',$row->id)
                ->select('source.*','journal.number as journal_number',
                    'journal.status as journal_status')->first();
            if($row->status==='approved' &&
                $request->user()->canAny(['finance.update','administration.update'])){
                $openingEquityLedgers=\App\Models\LedgerAccount::query()
                    ->where('type','equity')->where('is_active',true)
                    ->where('is_group',false)->orderBy('code')
                    ->get(['id','code','name']);
                $currencies=\App\Models\Currency::query()
                    ->where('is_active',true)->orderBy('code')
                    ->get(['id','code']);
            }
        }
        $dispatchDisposition=null;
        $dispatchEvidence=collect();
        if($row->case_type==='standalone_dispatch'){
            $dispatchDisposition=DB::table('finance_historical_dispatch_dispositions')
                ->where('finance_historical_correction_case_id',$row->id)->first();
            $dispatchEvidence=DB::table('finished_good_dispatch_lines as detail')
                ->join('production_batches as batch',
                    'batch.id','=','detail.production_batch_id')
                ->leftJoin('production_batch_cost_postings as posted',
                    'posted.production_batch_id','=','batch.id')
                ->leftJoin('currencies as currency',
                    'currency.id','=','posted.currency_id')
                ->leftJoin('journal_entries as finished_journal',
                    'finished_journal.id','=','posted.capitalization_journal_id')
                ->where('detail.finished_good_dispatch_id',$row->source_id)
                ->select('detail.id','detail.production_batch_id','detail.finished_good_id',
                    'detail.company_warehouse_id','detail.quantity_packages',
                    'batch.batch_number','batch.accepted_packages',
                    'posted.total_amount','posted.accounting_date',
                    'currency.code as original_cost_currency',
                    'finished_journal.status as capitalization_journal_status')
                ->orderBy('detail.id')->get();
        }
        return view('finance.historical-correction-show',[
            'case'=>$row,'types'=>HistoricalFinanceCorrectionService::TYPES,
            'events'=>$service->activity($row->id),
            'openingEquityLedgers'=>$openingEquityLedgers,
            'currencies'=>$currencies,'openingPosting'=>$openingPosting,
            'dispatchDisposition'=>$dispatchDisposition,
            'dispatchEvidence'=>$dispatchEvidence,
        ]);
    }

    public function review(Request $request,int $case,HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'review_notes'=>['required','string','min:20','max:4000'],
            'confirm_independent_review'=>['accepted'],
        ]);
        $service->review($case,$data,(int)$request->user()->id);
        return back()->with('success','Independent historical correction review recorded without changing a source document or accounting value.');
    }

    public function classifyDispatch(Request $request,int $case,
        HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'business_purpose'=>['required',Rule::in([
                'customer_sale','promotional_sample','production_loss',
                'internal_consumption','unresolved',
            ])],
            'supporting_document_reference'=>['required','string','min:4','max:180'],
            'source_evidence'=>['required','string','min:30','max:5000'],
            'remaining_financial_action'=>['required','string','min:30','max:5000'],
            'confirm_no_gl_posting'=>['accepted'],
        ]);
        $service->classifyStandaloneDispatch($case,$data,(int)$request->user()->id);
        return back()->with('success',
            'Historical warehouse-only dispatch purpose independently documented. No invoice, COGS journal or stock movement was created; the financial correction case remains open.');
    }

    public function withdraw(Request $request,int $case,HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'reason'=>['required','string','min:20','max:3000'],
            'confirm_withdrawal'=>['accepted'],
        ]);
        $service->withdraw($case,$data['reason'],(int)$request->user()->id);
        return back()->with('success',
            'Correction case withdrawn with permanent source and reviewer history. No accounting journal or physical stock was changed.');
    }

    public function postOpeningStock(Request $request,int $case,
        \App\Services\HistoricalOpeningStockValuationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'currency_id'=>['required','integer','exists:currencies,id'],
            'opening_equity_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'original_cost'=>['required','regex:/^(?:0|[1-9][0-9]{0,15})(?:\\.[0-9]{1,4})?$/','numeric','gt:0'],
            'posting_date'=>['required','date','before_or_equal:today'],
            'evidence_reference'=>['required','string','min:4','max:180'],
            'reconciliation_notes'=>['required','string','min:40','max:5000'],
            'confirm_no_prior_gl'=>['accepted'],
            'confirm_physical_source'=>['accepted'],
        ]);
        $posting=$service->post($case,$data,(int)$request->user()->id);
        return back()->with('success',
            'Original opening FIFO valuation and independent asset/equity journal posted (journal #'.
            $posting->journal_entry_id.'). The physical stock receipt and quantity were not changed.');
    }

    public function execute(Request $request,int $case,HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'resolution_reference'=>['required','string','min:4','max:180'],
            'resolution_notes'=>['required','string','min:20','max:4000'],
            'confirm_cogs_journal'=>['accepted'],
        ]);
        $count=$service->executeMissingCogs($case,$data,(int)$request->user()->id);
        return back()->with('success',$count.
            ' missing original-currency COGS journal(s) posted with immutable source evidence. All invoice lines were rechecked.');
    }
}
