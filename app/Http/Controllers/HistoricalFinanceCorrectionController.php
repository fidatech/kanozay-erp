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
        $historicalBatchPosting=null;
        $historicalBatchDispatches=collect();
        $historicalBatchAllocations=collect();
        $historicalBatchExpenseLedgers=collect();
        $historicalBatchReadiness=null;
        if($row->case_type==='batch_uncapitalized'){
            $historicalBatchPosting=DB::table('finance_historical_batch_capitalizations as plan')
                ->leftJoin('production_batch_cost_postings as cost',
                    'cost.id','=','plan.production_batch_cost_posting_id')
                ->leftJoin('currencies as currency','currency.id','=','plan.currency_id')
                ->where('plan.finance_historical_correction_case_id',$row->id)
                ->select('plan.*','cost.issue_journal_id','cost.capitalization_journal_id',
                    'currency.code as currency_code')->first();
            if($historicalBatchPosting){
                $historicalBatchAllocations=DB::table('finance_historical_batch_dispatch_allocations as posting')
                    ->leftJoin('ledger_accounts as ledger','ledger.id','=','posting.expense_ledger_id')
                    ->leftJoin('journal_entries as journal','journal.id','=','posting.journal_entry_id')
                    ->where('posting.finance_historical_batch_capitalization_id',$historicalBatchPosting->id)
                    ->select('posting.*','ledger.code as expense_code',
                        'ledger.name as expense_name','journal.number as journal_number',
                        'journal.status as journal_status')
                    ->orderBy('posting.dispatch_date')
                    ->orderBy('posting.finished_good_dispatch_line_id')->get();
            }
            if($row->status==='approved' &&
                $request->user()->canAny(['finance.update','administration.update'])){
                $reserved=DB::table('manufacturing_cost_ledger_policies')
                    ->pluck('cogs_ledger_id')->all();
                $historicalBatchExpenseLedgers=\App\Models\LedgerAccount::query()
                    ->where('type','expense')->where('is_active',true)
                    ->where('is_group',false)->whereNotIn('id',$reserved)
                    ->orderBy('code')->get(['id','code','name']);
            }
            $historicalBatchDispatches=DB::table('finished_good_dispatch_lines as source')
                ->join('finished_good_dispatches as dispatch',
                    'dispatch.id','=','source.finished_good_dispatch_id')
                ->leftJoin('sales_invoice_lines as invoice_line',
                    'invoice_line.dispatch_id','=','dispatch.id')
                ->leftJoin('sales_invoices as invoice','invoice.id','=','invoice_line.sales_invoice_id')
                ->leftJoin('finance_historical_dispatch_dispositions as signed',
                    'signed.finished_good_dispatch_id','=','dispatch.id')
                ->leftJoin('finance_historical_correction_cases as dispatch_case',
                    'dispatch_case.id','=','signed.finance_historical_correction_case_id')
                ->where('source.production_batch_id',$row->source_id)
                ->select('source.id as physical_line_id','source.quantity_packages',
                    'dispatch.number as dispatch_number',
                    'dispatch.dispatch_date','invoice.number as invoice_number',
                    'invoice.status as invoice_status','invoice.id as invoice_id',
                    'signed.business_purpose','signed.supporting_document_reference',
                    'signed.reviewed_by as purpose_reviewed_by',
                    'dispatch_case.status as purpose_case_status')
                ->orderBy('dispatch.dispatch_date')->orderBy('source.id')->get();
            $batch=\App\Models\ProductionBatch::with(['costs','finishedGood'])
                ->find($row->source_id);
            if($batch){
                $historicalBatchReadiness=app(\App\Services\ProductionCostReadinessService::class)
                    ->rows(collect([$batch]))->first();
            }
        }
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
        $dispatchCostSettlement=null;
        $dispatchCostSettlementLines=collect();
        $dispatchExpenseLedgers=collect();
        if($row->case_type==='standalone_dispatch'){
            $dispatchDisposition=DB::table('finance_historical_dispatch_dispositions')
                ->where('finance_historical_correction_case_id',$row->id)->first();
            $dispatchCostSettlement=DB::table('finance_historical_dispatch_cost_settlements')
                ->where('finance_historical_correction_case_id',$row->id)->first();
            if($dispatchCostSettlement){
                $dispatchCostSettlementLines=DB::table('finance_historical_dispatch_cost_lines as posting')
                    ->leftJoin('journal_entries as journal','journal.id','=','posting.journal_entry_id')
                    ->leftJoin('currencies as currency','currency.id','=','posting.currency_id')
                    ->where('posting.finance_historical_dispatch_cost_settlement_id',$dispatchCostSettlement->id)
                    ->select('posting.*','currency.code as currency_code',
                        'journal.number as journal_number','journal.status as journal_status')
                    ->orderBy('posting.finished_good_dispatch_line_id')->get();
            }
            if($dispatchDisposition && $row->status==='approved' &&
                in_array($dispatchDisposition->business_purpose,
                    ['promotional_sample','production_loss','internal_consumption'],true) &&
                $request->user()->canAny(['finance.update','administration.update'])){
                $reserved=DB::table('manufacturing_cost_ledger_policies')
                    ->pluck('cogs_ledger_id')->all();
                $dispatchExpenseLedgers=\App\Models\LedgerAccount::query()
                    ->where('type','expense')->where('is_active',true)
                    ->where('is_group',false)->whereNotIn('id',$reserved)
                    ->orderBy('code')->get(['id','code','name']);
            }
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
        $supplierDispute=null;
        $supplierReturnLines=collect();
        $supplierCreditCurrency=null;
        if($row->case_type==='supplier_return_unreconciled'){
            $supplierDispute=DB::table('finance_supplier_return_credit_disputes')
                ->where('finance_historical_correction_case_id',$row->id)->first();
            $supplierReturnLines=DB::table('purchase_return_record_lines')
                ->where('purchase_return_record_id',$row->source_id)
                ->orderBy('id')->get();
            $sourceCurrency=DB::table('purchase_return_records')
                ->where('id',$row->source_id)->value('currency_id');
            $supplierCreditCurrency=DB::table('currencies')
                ->where('id',$sourceCurrency)->value('code');
        }
        $shortCreditSettlement=null;
        $shortCreditPayables=collect();
        $shortCreditDifferenceLedgers=collect();
        if($row->case_type==='supplier_return_unreconciled'){
            $shortCreditSettlement=DB::table('finance_supplier_short_credit_settlements')
                ->where('finance_historical_correction_case_id',$row->id)->first();
            if($supplierDispute && $row->status==='approved' &&
                $request->user()->canAny(['finance.update','administration.update'])){
                $return=DB::table('purchase_return_records')
                    ->where('id',$row->source_id)->first();
                if($return){
                    $shortCreditPayables=DB::table('matched_supplier_invoices as invoice')
                        ->join('trade_documents as payable','payable.id','=','invoice.trade_document_id')
                        ->where('invoice.goods_receipt_id',$return->goods_receipt_id)
                        ->where('invoice.status','posted')
                        ->where('payable.currency_id',$return->currency_id)
                        ->get(['payable.id','payable.number','payable.amount','invoice.supplier_invoice_number']);
                }
                if($supplierDispute->requested_treatment!=='unresolved'){
                    $requiredType=$supplierDispute->requested_treatment==='supplier_claim_pending'?'asset':'expense';
                    $shortCreditDifferenceLedgers=\App\Models\LedgerAccount::query()
                        ->where('type',$requiredType)->where('is_active',true)
                        ->where('is_group',false)->orderBy('code')->get(['id','code','name']);
                }
            }
        }
        return view('finance.historical-correction-show',[
            'case'=>$row,'types'=>HistoricalFinanceCorrectionService::TYPES,
            'events'=>$service->activity($row->id),
            'historicalBatchPosting'=>$historicalBatchPosting,
            'historicalBatchDispatches'=>$historicalBatchDispatches,
            'historicalBatchReadiness'=>$historicalBatchReadiness,
            'historicalBatchAllocations'=>$historicalBatchAllocations,
            'historicalBatchExpenseLedgers'=>$historicalBatchExpenseLedgers,
            'openingEquityLedgers'=>$openingEquityLedgers,
            'currencies'=>$currencies,'openingPosting'=>$openingPosting,
            'dispatchDisposition'=>$dispatchDisposition,
            'dispatchEvidence'=>$dispatchEvidence,
            'dispatchCostSettlement'=>$dispatchCostSettlement,
            'dispatchCostSettlementLines'=>$dispatchCostSettlementLines,
            'dispatchExpenseLedgers'=>$dispatchExpenseLedgers,
            'supplierDispute'=>$supplierDispute,
            'supplierReturnLines'=>$supplierReturnLines,
            'supplierCreditCurrency'=>$supplierCreditCurrency,
            'shortCreditSettlement'=>$shortCreditSettlement,
            'shortCreditPayables'=>$shortCreditPayables,
            'shortCreditDifferenceLedgers'=>$shortCreditDifferenceLedgers,
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

    public function classifySupplierCredit(Request $request,int $case,
        HistoricalFinanceCorrectionService $service)
    {
        $this->gate($request,true);
        $request->merge([
            'supplier_offered_credit'=>\App\Support\LocalizedDecimal::normalize(
                $request->input('supplier_offered_credit')
            ),
        ]);
        $data=$request->validate([
            'supplier_offered_credit'=>[
                'required','regex:/^(?:0|[1-9][0-9]{0,15})(?:\\.[0-9]{1,4})?$/',
                'numeric','min:0',
            ],
            'requested_treatment'=>['required',Rule::in([
                'supplier_claim_pending','potential_nonrecoverable_loss','unresolved',
            ])],
            'supplier_document_reference'=>['required','string','min:4','max:180'],
            'source_evidence'=>['required','string','min:30','max:5000'],
            'remaining_approval_action'=>['required','string','min:30','max:5000'],
            'confirm_review_only'=>['accepted'],
        ]);
        $service->classifySupplierCreditDispute(
            $case,$data,(int)$request->user()->id
        );
        return back()->with('success',
            'Supplier-offered short credit documented against immutable original return price. No financial liability, receivable or loss was posted; approved accounting treatment is still outstanding.');
    }

    public function submitHistoricalBatch(Request $request,int $case,
        \App\Services\HistoricalBatchCapitalizationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'accounting_date'=>['required','date','before_or_equal:today'],
            'source_authorization_reference'=>['required','string','min:4','max:180'],
            'source_reconciliation_notes'=>['required','string','min:60','max:5000'],
            'expense_ledgers'=>['nullable','array','max:100'],
            'expense_ledgers.*'=>['required','integer','exists:ledger_accounts,id'],
            'confirm_all_original_sources'=>['accepted'],
        ]);
        $service->submit($case,$data,(int)$request->user()->id);
        return back()->with('success','Source-fingerprinted historical manufacturing, genuine invoice COGS and signed non-sale expenses submitted for separate approval. No journals or physical movements created.');
    }

    public function reviewHistoricalBatch(Request $request,int $case,
        \App\Services\HistoricalBatchCapitalizationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'approval_notes'=>['required','string','min:60','max:5000'],
            'confirm_historical_cost_review'=>['accepted'],
        ]);
        $service->approve($case,$data,(int)$request->user()->id);
        return back()->with('success','Independent historical original-currency manufacturing, sales and signed non-sale expense review recorded. No journals posted.');
    }

    public function postHistoricalBatch(Request $request,int $case,
        \App\Services\HistoricalBatchCapitalizationService $service)
    {
        $this->gate($request,true);
        $request->validate(['confirm_atomic_historical_posting'=>['accepted']]);
        $posting=$service->post($case,(int)$request->user()->id);
        return back()->with('success',
            'Source-backed manufacturing WIP, finished inventory, '.
            $posting->posted_invoice_cogs_lines.' genuine invoice COGS line(s) and '.
            $posting->posted_non_sale_lines.' signed non-sale expense line(s) posted atomically. No physical stock or sales revenue was changed.');
    }

    public function submitDispatchCost(Request $request,int $case,
        \App\Services\HistoricalDispatchCostSettlementService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'posting_date'=>['required','date','before_or_equal:today'],
            'expense_ledgers'=>['required','array','min:1','max:50'],
            'expense_ledgers.*'=>['required','integer','exists:ledger_accounts,id'],
            'source_authorization_reference'=>['required','string','min:4','max:180'],
            'expense_recognition_evidence'=>['required','string','min:50','max:5000'],
            'confirm_original_non_sale_cost'=>['accepted'],
        ]);
        $service->submit($case,$data,(int)$request->user()->id);
        return back()->with('success',
            'Original-currency dispatch expense submitted for independent review. No sale, stock movement or GL journal was created.');
    }

    public function approveDispatchCost(Request $request,int $case,
        \App\Services\HistoricalDispatchCostSettlementService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'approval_notes'=>['required','string','min:40','max:5000'],
            'confirm_independent_cost'=>['accepted'],
        ]);
        $service->approve($case,$data,(int)$request->user()->id);
        return back()->with('success',
            'Independent purpose-specific dispatch expense decision recorded. No journal posted.');
    }

    public function postDispatchCost(Request $request,int $case,
        \App\Services\HistoricalDispatchCostSettlementService $service)
    {
        $this->gate($request,true);
        $request->validate(['confirm_original_cost_posting'=>['accepted']]);
        $result=$service->post($case,(int)$request->user()->id);
        return back()->with('success',
            'Original batch source-cost non-sale dispatch expenses posted for settlement #'.$result->id.
            '. No customer sale, revenue, receivable or duplicate physical movement was created.');
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

    public function submitShortCredit(Request $request,int $case,
        \App\Services\SupplierShortCreditSettlementService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'trade_document_id'=>['required','integer','exists:trade_documents,id'],
            'difference_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'treatment'=>['required',Rule::in(['recognized_supplier_claim','approved_nonrecoverable_loss'])],
            'posting_date'=>['required','date','before_or_equal:today'],
            'settlement_reference'=>['required','string','min:4','max:180'],
            'recognition_evidence'=>['required','string','min:50','max:5000'],
            'confirm_signed_recognition'=>['accepted'],
        ]);
        $service->submit($case,$data,(int)$request->user()->id);
        return back()->with('success','Source-matched supplier short-credit settlement submitted for independent approval. No GL journal was posted.');
    }

    public function approveShortCredit(Request $request,int $case,
        \App\Services\SupplierShortCreditSettlementService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'approval_notes'=>['required','string','min:40','max:5000'],
            'confirm_independent_settlement'=>['accepted'],
        ]);
        $service->approve($case,$data,(int)$request->user()->id);
        return back()->with('success','Independent supplier claim/loss decision recorded without posting a journal.');
    }

    public function postShortCredit(Request $request,int $case,
        \App\Services\SupplierShortCreditSettlementService $service)
    {
        $this->gate($request,true);
        $request->validate(['confirm_post_short_credit'=>['accepted']]);
        $settlement=$service->post($case,(int)$request->user()->id);
        return back()->with('success','Source-priced supplier partial credit and approved claim/loss posted in journal #'.$settlement->journal_entry_id.'. No new physical stock movement was made.');
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
