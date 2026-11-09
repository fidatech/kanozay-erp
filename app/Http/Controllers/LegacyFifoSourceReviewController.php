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
        return view('inventory.legacy-fifo-reviews',[
            'layers'=>$layers,'reviews'=>$reviews,'warehouses'=>$warehouses,
        ]);
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
