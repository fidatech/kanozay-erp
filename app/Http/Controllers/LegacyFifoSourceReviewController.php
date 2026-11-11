<?php

namespace App\Http\Controllers;

use App\Services\LegacyFifoSourceReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LegacyFifoSourceReviewController extends Controller
{
    use \App\Http\Controllers\Concerns\BlocksUnverifiedLegacyRecovery;

    /** Every write on this screen is legacy cost recovery without acceptance tests. */
    public const UNVERIFIED_LEGACY_ACTIONS = [
        'consumed', 'review', 'submitCost', 'reviewCost', 'postCost',
        'submitProductionCost', 'reviewProductionCost',
        'submitProductionRecovery', 'reviewProductionRecovery', 'postProductionRecovery',
        'submitMultiProductionRecovery', 'reviewMultiProductionRecovery', 'postMultiProductionRecovery',
    ];

    private function authorizeAccess(Request $request,bool $write=false): void
    {
        abort_unless($write
            ?($request->user()->can('administration.update') ||
               ($request->user()->can('inventory.update') &&
                $request->user()->can('finance.update')))
            :$request->user()->canAny([
                'inventory.view','finance.view','administration.view',
                'inventory.update','finance.update',
            ]),403);
    }

    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        $layers=DB::table('inventory_cost_layers as lot')
            ->join('inventory_materials as material',
                'material.id','=','lot.inventory_material_id')
            ->leftJoin('inventory_stock_movements as origin',function($join){
                $join->on('origin.id','=','lot.source_id')
                    ->where('lot.source_type','=','inventory_stock_movement');
            })
            ->where('lot.source_type','inventory_stock_movement')
            ->where('origin.type','opening')
            ->where('origin.direction','in')
            ->select('lot.*','material.sku','material.name as material_name',
                'origin.created_by as opening_registrar_id')
            ->orderByDesc('lot.id')->limit(150)->get();
        $positions=DB::table('inventory_cost_layer_locations')
            ->whereIn('inventory_cost_layer_id',$layers->pluck('id'))
            ->get()->groupBy('inventory_cost_layer_id');
        foreach($layers as $lot){
            $qty=\Brick\Math\BigDecimal::zero();
            foreach($positions->get($lot->id,collect()) as $position)
                $qty=$qty->plus((string)$position->quantity);
            $lot->consumed_quantity=(string)\Brick\Math\BigDecimal::of(
                    (string)$lot->original_quantity
                )->minus((string)$lot->remaining_quantity)->toScale(6);
            $lot->unpositioned_quantity=(string)\Brick\Math\BigDecimal::of(
                    (string)$lot->remaining_quantity
                )->minus($qty)->toScale(6);
            $lot->unassigned_quantity=(string)$positions->get($lot->id,collect())
                ->where('location_key','unassigned')->sum('quantity');
        }

        $reviews=DB::table('finance_legacy_fifo_source_reviews as review')
            ->join('inventory_cost_layers as lot','lot.id','=','review.inventory_cost_layer_id')
            ->join('inventory_materials as material',
                'material.id','=','review.inventory_material_id')
            ->leftJoin('company_warehouses as warehouse',
                'warehouse.id','=','review.company_warehouse_id')
            ->select('review.*','material.sku','warehouse.name as warehouse_name')
            ->orderByDesc('review.id')->paginate(30);
        $warehouses=DB::table('company_warehouses')->where('is_active',true)
            ->orderBy('name')->get(['id','name','code']);
        $sourceCostReviews=DB::table('finance_legacy_fifo_source_reviews as review')
            ->join('inventory_cost_layers as lot',
                'lot.id','=','review.inventory_cost_layer_id')
            ->join('inventory_materials as material',
                'material.id','=','review.inventory_material_id')
            ->leftJoin('finance_historical_fifo_adjustment_valuations as booked',
                'booked.inventory_cost_layer_id','=','review.inventory_cost_layer_id')
            ->where('review.review_type','consumed_provenance')
            ->where('review.status','approved')
            ->whereNull('booked.id')
            ->select('review.*','material.sku','lot.source_id',
                'lot.original_cost as source_lot_cost')
            ->orderByDesc('review.id')->limit(60)->get();
        $historicCostPlans=DB::table('finance_historical_fifo_adjustment_valuations as financial')
            ->join('inventory_cost_layers as lot',
                'lot.id','=','financial.inventory_cost_layer_id')
            ->join('inventory_materials as material',
                'material.id','=','lot.inventory_material_id')
            ->leftJoin('currencies as currency','currency.id','=','financial.currency_id')
            ->leftJoin('journal_entries as journal',
                'journal.id','=','financial.opening_journal_entry_id')
            ->select('financial.*','material.sku','currency.code as currency_code',
                'journal.number as opening_journal_number',
                'journal.status as opening_journal_status')
            ->orderByDesc('financial.id')->limit(60)->get();
        $historicIssueCosts=DB::table('finance_historical_fifo_adjustment_cost_lines as cost')
            ->leftJoin('journal_entries as journal','journal.id','=','cost.journal_entry_id')
            ->whereIn('cost.finance_historical_fifo_adjustment_valuation_id',
                $historicCostPlans->pluck('id'))
            ->select('cost.*','journal.number as journal_number',
                'journal.status as journal_status')
            ->orderBy('cost.original_issue_date')
            ->orderBy('cost.inventory_cost_consumption_id')->get()
            ->groupBy('finance_historical_fifo_adjustment_valuation_id');
        $productionSourceReviews=DB::table('finance_legacy_fifo_source_reviews as review')
            ->join('inventory_cost_layers as lot','lot.id','=','review.inventory_cost_layer_id')
            ->join('inventory_materials as material','material.id','=','review.inventory_material_id')
            ->leftJoin('finance_historical_fifo_production_cost_reviews as booked',
                'booked.inventory_cost_layer_id','=','review.inventory_cost_layer_id')
            ->leftJoin('finance_historical_fifo_adjustment_valuations as adjustment',
                'adjustment.inventory_cost_layer_id','=','review.inventory_cost_layer_id')
            ->where('review.review_type','consumed_provenance')
            ->where('review.status','approved')
            ->whereNull('lot.original_cost')
            ->whereNull('booked.id')->whereNull('adjustment.id')
            ->select('review.*','material.sku')
            ->orderByDesc('review.id')->limit(60)->get();
        $productionPlans=DB::table('finance_historical_fifo_production_cost_reviews as review')
            ->join('inventory_cost_layers as lot','lot.id','=','review.inventory_cost_layer_id')
            ->join('inventory_materials as material','material.id','=','lot.inventory_material_id')
            ->leftJoin('currencies as c','c.id','=','review.currency_id')
            ->select('review.*','material.sku','c.code as currency_code')
            ->orderByDesc('review.id')->limit(60)->get();
        $productionAllocations=DB::table('finance_historical_fifo_production_cost_lines as allocation')
            ->leftJoin('production_batches as batch',
                'batch.id','=','allocation.production_batch_id')
            ->whereIn('allocation.finance_historical_fifo_production_cost_review_id',
                $productionPlans->pluck('id'))
            ->select('allocation.*','batch.batch_number')
            ->orderBy('allocation.original_material_issue_date')
            ->orderBy('allocation.inventory_cost_consumption_id')->get()
            ->groupBy('finance_historical_fifo_production_cost_review_id');
        $productionBatchIds=$productionAllocations->flatten()
            ->pluck('production_batch_id')->unique()->values();
        $productionBatchDependencies=collect();
        if($productionBatchIds->isNotEmpty()){
            $sourceBatchCosts=DB::table('production_batch_costs')
                ->whereIn('production_batch_id',$productionBatchIds)
                ->get()->keyBy('production_batch_id');
            $existingBatchGL=DB::table('production_batch_cost_postings')
                ->whereIn('production_batch_id',$productionBatchIds)
                ->get()->keyBy('production_batch_id');
            $sourceDispatches=DB::table('finished_good_dispatch_lines')
                ->whereIn('production_batch_id',$productionBatchIds)
                ->select('production_batch_id')->get()
                ->groupBy('production_batch_id');
            $genuineInvoices=DB::table('sales_invoice_lines')
                ->whereIn('production_batch_id',$productionBatchIds)
                ->select('production_batch_id')->get()
                ->groupBy('production_batch_id');
            $productionBatchDependencies=$productionBatchIds->mapWithKeys(
                fn($id)=>[$id=>[
                    'frozen_material_cost'=>$sourceBatchCosts->get($id)?->material_cost,
                    'unvalued_quantity'=>$sourceBatchCosts->get($id)?->unvalued_quantity,
                    'currency_code'=>$sourceBatchCosts->get($id)?->currency_code,
                    'manufacturing_gl_id'=>$existingBatchGL->get($id)?->id,
                    'dispatched_lines'=>$sourceDispatches->get($id,collect())->count(),
                    'invoiced_lines'=>$genuineInvoices->get($id,collect())->count(),
                ]]
            );
        }
        $multiRecoveryCandidates=$productionAllocations->flatten(1)
            ->groupBy('production_batch_id')
            ->map(function($allocations,$batchId)use($productionPlans){
                $ids=$allocations->pluck(
                    'finance_historical_fifo_production_cost_review_id'
                )->unique()->values();
                $plans=$productionPlans
                    ->whereIn('id',$ids)->where('status','approved')->values();
                return [
                    'batch_id'=>(int)$batchId,'plans'=>$plans,
                    'batch_number'=>$allocations->first()?->batch_number,
                ];
            })->filter(fn($group)=>$group['plans']->count()>=2)->values();
        $multiRecoveryCases=DB::table('finance_historical_fifo_multi_recoveries as recovery')
            ->join('production_batches as batch',
                'batch.id','=','recovery.production_batch_id')
            ->leftJoin('currencies as currency',
                'currency.id','=','recovery.currency_id')
            ->leftJoin('journal_entries as wip',
                'wip.id','=','recovery.wip_journal_id')
            ->leftJoin('journal_entries as fg',
                'fg.id','=','recovery.finished_journal_id')
            ->select('recovery.*','batch.batch_number',
                'currency.code as currency_code',
                'wip.number as wip_journal_number',
                'fg.number as finished_journal_number')
            ->orderByDesc('recovery.id')->limit(60)->get();
        $multiRecoveryCandidates=$multiRecoveryCandidates
            ->filter(fn($group)=>!$multiRecoveryCases
                ->contains('production_batch_id',$group['batch_id']))
            ->values();
        $multiRecoveryLots=DB::table('finance_historical_fifo_multi_recovery_lots as lot')
            ->leftJoin('journal_entries as opening',
                'opening.id','=','lot.opening_journal_id')
            ->whereIn('lot.finance_historical_fifo_multi_recovery_id',
                $multiRecoveryCases->pluck('id'))
            ->select('lot.*',
                'opening.number as opening_journal_number')
            ->orderBy('lot.inventory_cost_layer_id')->get()
            ->groupBy('finance_historical_fifo_multi_recovery_id');
        $productionRecoveries=DB::table('finance_historical_fifo_production_recoveries as recovery')
            ->join('finance_historical_fifo_production_cost_reviews as source',
                'source.id','=','recovery.finance_historical_fifo_production_cost_review_id')
            ->leftJoin('production_batches as batch',
                'batch.id','=','recovery.production_batch_id')
            ->leftJoin('currencies as currency',
                'currency.id','=','recovery.currency_id')
            ->leftJoin('journal_entries as original',
                'original.id','=','recovery.opening_journal_id')
            ->leftJoin('journal_entries as wip',
                'wip.id','=','recovery.wip_journal_id')
            ->leftJoin('journal_entries as finished',
                'finished.id','=','recovery.finished_journal_id')
            ->select('recovery.*','batch.batch_number',
                'currency.code as currency_code',
                'original.number as opening_journal_number',
                'wip.number as wip_journal_number',
                'finished.number as finished_journal_number')
            ->orderByDesc('recovery.id')->limit(60)->get();
        $recoveredWipSourceIds=$productionRecoveries
            ->pluck('finance_historical_fifo_production_cost_review_id');
        $currencies=DB::table('currencies')
            ->where('is_active',true)->orderBy('code')->get(['id','code']);
        $equityLedgers=DB::table('ledger_accounts')
            ->where('type','equity')->where('is_active',true)
            ->where('is_group',false)->orderBy('code')->get(['id','code','name']);
        $expenseLedgers=DB::table('ledger_accounts')
            ->where('type','expense')->where('is_active',true)
            ->where('is_group',false)->orderBy('code')->get(['id','code','name']);
        return view('inventory.legacy-fifo-reviews',[
            'layers'=>$layers,'reviews'=>$reviews,'warehouses'=>$warehouses,
            'sourceCostReviews'=>$sourceCostReviews,
            'productionSourceReviews'=>$productionSourceReviews,
            'productionPlans'=>$productionPlans,
            'productionAllocations'=>$productionAllocations,
            'productionBatchDependencies'=>$productionBatchDependencies,
            'productionRecoveries'=>$productionRecoveries,
            'multiRecoveryCandidates'=>$multiRecoveryCandidates,
            'multiRecoveryCases'=>$multiRecoveryCases,
            'multiRecoveryLots'=>$multiRecoveryLots,
            'recoveredWipSourceIds'=>$recoveredWipSourceIds,
            'historicCostPlans'=>$historicCostPlans,
            'historicIssueCosts'=>$historicIssueCosts,
            'currencies'=>$currencies,
            'equityLedgers'=>$equityLedgers,
            'expenseLedgers'=>$expenseLedgers,
        ]);
    }

    public function submitMultiProductionRecovery(Request $request,
        \App\Services\HistoricalProductionFifoMultiRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'source_cost_review_ids'=>['required','array','min:2','max:12'],
            'source_cost_review_ids.*'=>[
                'required','integer','distinct',
                'exists:finance_historical_fifo_production_cost_reviews,id',
            ],
            'opening_equity_ledger_id'=>[
                'required','integer','exists:ledger_accounts,id',
            ],
            'approval_reference'=>['required','string','min:4','max:180'],
            'reconciliation_notes'=>['required','string','min:120','max:5000'],
            'confirm_original_ownership_sources'=>['accepted'],
            'confirm_all_material_inputs'=>['accepted'],
            'confirm_one_currency_one_issue_date'=>['accepted'],
        ]);
        $service->submit($data['source_cost_review_ids'],$data,
            (int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Original multi-FIFO opening/raw/WIP/finished and genuine invoice-COGS source case submitted for independent finance approval. No GL, original issues or customer sale was modified.'
        );
    }

    public function reviewMultiProductionRecovery(Request $request,int $recovery,
        \App\Services\HistoricalProductionFifoMultiRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'review_reference'=>['required','string','min:4','max:180'],
            'review_notes'=>['required','string','min:120','max:5000'],
            'confirm_independently_reviewed_all_lots'=>['accepted'],
        ]);
        $result=$service->approve($recovery,$data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success',$result->status==='approved'
                ?'Original multi-FIFO lot ownership, genuine issued material, accepted finished source and original customer sale/COGS chronology approved. Separate final executor required.'
                :'Unsupported coordinated original FIFO cost source rejected without GL.'
        );
    }

    public function postMultiProductionRecovery(Request $request,int $recovery,
        \App\Services\HistoricalProductionFifoMultiRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $request->validate([
            'confirm_original_months_open'=>['accepted'],
            'confirm_no_duplicate_manual_GL'=>['accepted'],
            'confirm_atomic_full_chain'=>['accepted'],
        ]);
        $result=$service->post($recovery,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Approved original multiple FIFO opening assets, combined WIP, QC finished goods and authentic invoice COGS source postings committed together. Source manufacturing posting #'.$result->production_batch_cost_posting_id
        );
    }

    public function submitProductionRecovery(Request $request,
        \App\Services\HistoricalProductionFifoRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'source_cost_review_id'=>[
                'required','integer',
                'exists:finance_historical_fifo_production_cost_reviews,id',
            ],
            'opening_equity_ledger_id'=>[
                'required','integer','exists:ledger_accounts,id',
            ],
            'approval_reference'=>['required','string','min:4','max:180'],
            'reconciliation_notes'=>['required','string','min:120','max:5000'],
            'confirm_original_documents'=>['accepted'],
            'confirm_no_other_raw_materials'=>['accepted'],
            'confirm_historical_finance_periods'=>['accepted'],
        ]);
        $service->submit((int)$data['source_cost_review_id'],$data,
            (int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')
            ->with('success','Source-fingerprinted original opening/WIP/finished goods and real invoice-COGS plan submitted for separate independent authorization. No GL or physical document changed.');
    }

    public function reviewProductionRecovery(Request $request,int $recovery,
        \App\Services\HistoricalProductionFifoRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'review_reference'=>['required','string','min:4','max:180'],
            'review_notes'=>['required','string','min:120','max:5000'],
            'confirm_independent_full_chain'=>['accepted'],
        ]);
        $result=$service->approve($recovery,$data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success',$result->status==='approved'
            ?'Independent finance review approved original opening, genuine WIP, released finished stock and actual customer COGS source chronology; separate executor must perform final GL.'
            :'Unsupported historical production FIFO accounting plan rejected. No stock or GL changed.'
        );
    }

    public function postProductionRecovery(Request $request,int $recovery,
        \App\Services\HistoricalProductionFifoRecoveryService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $request->validate([
            'confirm_open_source_periods'=>['accepted'],
            'confirm_no_prior_gl'=>['accepted'],
            'confirm_atomic_accounting'=>['accepted'],
        ]);
        $result=$service->post($recovery,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Source-backed original opening FIFO, production WIP, QC finished asset and genuine invoiced COGS posted atomically, with no physical inventory or original customer document mutation. Manufacturing GL #'.$result->production_batch_cost_posting_id
        );
    }

    public function submitProductionCost(Request $request,
        \App\Services\HistoricalProductionFifoSourceCostReviewService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'finance_legacy_fifo_source_review_id'=>[
                'required','integer','exists:finance_legacy_fifo_source_reviews,id',
            ],
            'currency_id'=>['required','integer','exists:currencies,id'],
            'original_lot_cost'=>[
                'required','string',
                'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/',
            ],
            'original_cost_evidence_reference'=>['required','string','min:4','max:180'],
            'original_cost_evidence_notes'=>['required','string','min:80','max:5000'],
            'confirm_original_purchase_source'=>['accepted'],
            'confirm_no_financial_posting'=>['accepted'],
        ]);
        $service->submit((int)$data['finance_legacy_fifo_source_review_id'],
            $data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Documented opening FIFO production-source WIP cost proposal created. NO GL, original issue, production cost snapshot, physical inventory or COGS was changed.'
        );
    }

    public function reviewProductionCost(Request $request,int $plan,
        \App\Services\HistoricalProductionFifoSourceCostReviewService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'approval_notes'=>['required','string','min:80','max:5000'],
            'confirm_source_and_downstream_dependencies'=>['accepted'],
        ]);
        $result=$service->approve($plan,$data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')
            ->with('success',$result->status==='approved'
                ?'Original FIFO production WIP source allocations independently reviewed. DOCUMENTARY APPROVAL ONLY: historical WIP/finished-goods/sale COGS GL has NOT been posted.'
                :'Unsupported production FIFO source cost rejected. No journals or original FIFO issues were changed.');
    }

    public function submitCost(Request $request,
        \App\Services\HistoricalConsumedFifoAdjustmentValuationService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $request->merge([
            'original_total_cost'=>\App\Support\LocalizedDecimal::normalize(
                $request->input('original_total_cost')
            ),
        ]);
        $data=$request->validate([
            'finance_legacy_fifo_source_review_id'=>[
                'required','integer','exists:finance_legacy_fifo_source_reviews,id',
            ],
            'currency_id'=>['required','integer','exists:currencies,id'],
            'opening_equity_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'adjustment_loss_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'original_total_cost'=>[
                'required','string',
                'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/',
            ],
            'original_cost_evidence_reference'=>['required','string','min:4','max:180'],
            'source_cost_reconciliation'=>['required','string','min:80','max:5000'],
            'confirm_evidence_original_cost'=>['accepted'],
            'confirm_no_duplicate_manual_gl'=>['accepted'],
        ]);
        $service->submit((int)$data['finance_legacy_fifo_source_review_id'],
            $data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Original source-documented opening FIFO cost and adjustment-loss fractions submitted for independent approval. No old issues, stock or GL were changed.'
        );
    }

    public function reviewCost(Request $request,int $plan,
        \App\Services\HistoricalConsumedFifoAdjustmentValuationService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'approval_reference'=>['required','string','min:4','max:180'],
            'approval_notes'=>['required','string','min:80','max:5000'],
            'confirm_independent_cost_review'=>['accepted'],
        ]);
        $result=$service->approve($plan,$data,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')
            ->with('success',$result->status==='approved'
                ?'Independent original FIFO opening source cost and physical adjustment-loss account review approved. No journal posted; separate executor required.'
                :'Historical consumed FIFO original-cost proposal rejected. No GL or stock changed.');
    }

    public function postCost(Request $request,int $plan,
        \App\Services\HistoricalConsumedFifoAdjustmentValuationService $service)
    {
        $this->authorizeAccess($request,true);
        abort_unless($request->user()->can('finance.update') ||
            $request->user()->can('administration.update'),403);
        $request->validate([
            'confirm_original_open_periods'=>['accepted'],
            'confirm_original_cost_not_manual_gl'=>['accepted'],
        ]);
        $result=$service->post($plan,(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')->with(
            'success','Original opening FIFO inventory/equity and historical adjustment write-off expense posted under source-linked journal approvals. Original FIFO issues and physical stock unchanged. Opening GL #'.$result->opening_journal_entry_id
        );
    }

    public function consumed(Request $request,LegacyFifoSourceReviewService $service)
    {
        $this->authorizeAccess($request,true);
        $data=$request->validate([
            'inventory_cost_layer_id'=>['required','integer','exists:inventory_cost_layers,id'],
            'source_evidence_reference'=>['required','string','min:4','max:180'],
            'source_reconciliation_notes'=>['required','string','min:60','max:5000'],
            'confirm_no_finance_posting'=>['accepted'],
        ]);
        $service->submit((int)$data['inventory_cost_layer_id'],[
            'review_type'=>'consumed_provenance',
            'source_evidence_reference'=>$data['source_evidence_reference'],
            'source_reconciliation_notes'=>$data['source_reconciliation_notes'],
        ],(int)$request->user()->id);
        return redirect()->route('inventory.legacy-fifo-reviews.index')
            ->with('success','Original consumed FIFO movement evidence submitted for independent review. No historic cost, issue or GL journal was created.');
    }

    public function review(Request $request,int $review,LegacyFifoSourceReviewService $service)
    {
        $this->authorizeAccess($request,true);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'decision_reference'=>['required','string','min:4','max:180'],
            'decision_notes'=>['required','string','min:60','max:5000'],
            'confirm_independent_review'=>['accepted'],
        ]);
        $result=$service->review($review,$data,(int)$request->user()->id);
        return back()->with('success',$result->status==='rejected'
            ?'Historical source review rejected. No physical stock, FIFO consumption or GL changes.'
            :($result->review_type==='consumed_provenance'
                ?'Consumed historical FIFO provenance approved as documentary evidence ONLY. Cost and accounting still require genuine original evidence and a separate authorization.'
                :'Approved: existing remaining FIFO quantity attributed to physically counted warehouse. No stock movement, FIFO cost or GL value created.'));
    }
}
