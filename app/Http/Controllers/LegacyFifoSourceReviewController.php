<?php

namespace App\Http\Controllers;

use App\Services\LegacyFifoSourceReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LegacyFifoSourceReviewController extends Controller
{
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
            'historicCostPlans'=>$historicCostPlans,
            'historicIssueCosts'=>$historicIssueCosts,
            'currencies'=>$currencies,
            'equityLedgers'=>$equityLedgers,
            'expenseLedgers'=>$expenseLedgers,
        ]);
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
