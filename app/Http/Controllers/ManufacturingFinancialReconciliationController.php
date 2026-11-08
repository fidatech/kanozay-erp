<?php

namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Services\ManufacturingFinancialReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManufacturingFinancialReconciliationController extends Controller
{
    public function index(Request $request,ManufacturingFinancialReconciliationService $service)
    {
        abort_unless($request->user()->canAny([
            'finance.view','reports.view','manufacturing.view','administration.view',
        ]),403);
        $filters=$request->validate([
            'status'=>['nullable',Rule::in(['released','qc_approved','qc_rejected','pending_qc'])],
            'export'=>['nullable',Rule::in(['csv'])],
        ]);
        $query=ProductionBatch::query()
            ->with('finishedGood')
            ->when($filters['status']??null,fn($q,$value)=>$q->where('status',$value));

        if(($filters['export']??null)==='csv'){
            return response()->streamDownload(function()use($query,$service){
                $out=fopen('php://output','w');
                fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Batch','Product','Status','Original currency','Accepted packages',
                    'Warehouse received packages','Gross physical dispatches','QC restocked packages',
                    'Packages costed to COGS','Packages costed to non-sale expenses','Costed QC returns',
                    'Physical remaining packages','Financial remaining packages',
                    'Gross source material cost','Abnormal FIFO loss expensed',
                    'Net capitalized value','Posted COGS','Posted non-sale inventory expenses','Returned inventory cost',
                    'Financial remaining asset value','Reconciliation exceptions']);
                $query->orderBy('id')->chunkById(75,function($batches)use($service,$out){
                    foreach($service->rows($batches) as $row){
                        $fields=[
                            $row['batch'],$row['product'],$row['status'],$row['currency'],
                            $row['accepted'],$row['received'],$row['dispatched'],$row['qc_returns'],
                            $row['costed_sales'],$row['costed_non_sale'],$row['costed_recoveries'],
                            $row['net_physical'],$row['remaining_costed'],
                            $row['gross_material_source_cost'],
                            $row['abnormal_waste_expensed'],
                            $row['total_capitalized'],$row['posted_cogs'],
                            $row['posted_non_sale_expense'],$row['returned_cost'],$row['remaining_asset_value'],
                            implode(' | ',$row['issues']),
                        ];
                        fputcsv($out,array_map(
                            [ReportCenterController::class,'csvSafe'],$fields
                        ));
                    }
                });
                fclose($out);
            },'kanozay-manufacturing-financial-reconciliation-'.
                today()->format('Ymd').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $batches=$query->orderByDesc('id')->paginate(25)->withQueryString();
        $rows=$service->rows($batches->getCollection());
        $exceptions=$rows->filter(fn($row)=>count($row['issues'])>0)->count();
        return view('reports.manufacturing-financial-reconciliation',compact(
            'batches','rows','exceptions'
        ));
    }
}
