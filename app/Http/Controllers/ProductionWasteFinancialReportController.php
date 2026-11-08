<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionWasteFinancialReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny([
            'manufacturing.view','finance.view','reports.view','administration.view',
        ]),403);
        $filters=$request->validate([
            'from'=>['nullable','date'],
            'to'=>['nullable','date','after_or_equal:from'],
            'treatment'=>['nullable',Rule::in([
                'pending','normal_process_loss','abnormal_loss','rework_required',
            ])],
            'export'=>['nullable',Rule::in(['csv'])],
        ]);
        $query=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as item',
                'item.id','=','waste.production_order_material_id')
            ->join('production_orders as plan',
                'plan.id','=','item.production_order_id')
            ->leftJoin('production_batches as batch',
                'batch.production_order_id','=','order.id')
            ->leftJoin('production_waste_financial_reviews as review',
                'review.production_material_waste_id','=','waste.id')
            ->leftJoin('production_abnormal_waste_cost_reviews as abnormal',
                'abnormal.production_material_waste_id','=','waste.id')
            ->leftJoin('currencies as cost_currency',
                'cost_currency.id','=','abnormal.currency_id')
            ->leftJoin('production_batch_cost_postings as posting',
                'posting.production_batch_id','=','batch.id')
            ->leftJoin('journal_entries as batch_journal',
                'batch_journal.id','=','posting.issue_journal_id')
            ->when($filters['from']??null,
                fn($q,$date)=>$q->whereDate('waste.created_at','>=',$date))
            ->when($filters['to']??null,
                fn($q,$date)=>$q->whereDate('waste.created_at','<=',$date))
            ->when($filters['treatment']??null,function($q,$status){
                if($status==='pending')$q->whereNull('review.id');
                else $q->where('review.treatment',$status);
            })
            ->select('waste.id','waste.quantity','waste.reason',
                'waste.created_at','item.material_name','item.unit',
                'plan.id as production_order_id','plan.number as order_number',
                'plan.status as order_status','batch.batch_number',
                'batch.status as batch_status',
                'review.treatment','review.approval_reference',
                'review.review_notes','review.reviewed_by',
                'abnormal.source_cost_amount as abnormal_cost',
                'abnormal.approval_reference as cost_approval_reference',
                'cost_currency.code as cost_currency',
                'batch_journal.number as batch_expense_journal');
        if(($filters['export']??null)==='csv'){
            return response()->streamDownload(function()use($query){
                $out=fopen('php://output','w');
                fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Production order','Batch','Recorded on','Material',
                    'Original material unit','Previously issued waste quantity',
                    'Reason','Financial treatment','Approval reference',
                    'Reviewer identifier','Review notes',
                    'Approved abnormal FIFO source cost','Original currency',
                    'Abnormal cost approval','Batch GL journal']);
                foreach((clone $query)->orderBy('waste.id')->cursor() as $row){
                    fputcsv($out,array_map(
                        [ReportCenterController::class,'csvSafe'],[
                            $row->order_number,$row->batch_number,
                            $row->created_at,$row->material_name,$row->unit,
                            $row->quantity,$row->reason,
                            $row->treatment??'Pending review',
                            $row->approval_reference,$row->reviewed_by,
                            $row->review_notes,
                            $row->abnormal_cost,$row->cost_currency,
                            $row->cost_approval_reference,$row->batch_expense_journal,
                        ]
                    ));
                }
                fclose($out);
            },'kanozay-material-waste-financial-review-'.
                today()->format('Ymd').'.csv',
                ['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $rows=$query->orderByDesc('waste.id')->paginate(25)->withQueryString();
        return view('reports.production-waste-financial',compact('rows','filters'));
    }
}
