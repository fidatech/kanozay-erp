<?php

namespace App\Http\Controllers;

use App\Models\FinishedGoodDispatchLine;
use App\Services\DispatchCostReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DispatchCostReconciliationController extends Controller
{
    public function index(Request $request,DispatchCostReconciliationService $service)
    {
        abort_unless($request->user()->canAny([
            'reports.view','finance.view','manufacturing.view','administration.view',
        ]),403);
        $filters=$request->validate([
            'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],
            'export'=>['nullable',Rule::in(['csv'])],
        ]);
        $from=$filters['from']??now()->startOfMonth()->toDateString();
        $to=$filters['to']??now()->toDateString();
        $query=FinishedGoodDispatchLine::query()
            ->with(['dispatch','batch'])
            ->whereHas('dispatch',fn($q)=>$q->whereBetween('dispatch_date',[$from,$to]));
        if(($filters['export']??null)==='csv'){
            return response()->streamDownload(function()use($query,$service){
                $out=fopen('php://output','w');
                fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Dispatch','Date','Batch','Product','Packages',
                    'Original cost currency','Indicative source cost per package',
                    'Indicative source cost of dispatched quantity','Cost review state','Reasons']);
                $query->orderBy('id')->chunkById(100,function($chunk)use($service,$out){
                    foreach($service->rows($chunk) as $row){
                        fputcsv($out,array_map(
                            [ReportCenterController::class,'csvSafe'],[
                            $row['reference'],$row['date'],$row['batch'],$row['product'],
                            $row['packages'],$row['currency'],
                            $row['unit_cost'],$row['indicative_cost'],
                            $row['status'],implode('; ',$row['reasons']),
                        ]));
                    }
                });
                fclose($out);
            },'kanozay-dispatch-cost-reconciliation-'.$from.'-'.$to.'.csv',
              ['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $records=(clone $query)->orderByDesc('id')->paginate(30)->withQueryString();
        $rows=$service->rows($records->getCollection());
        $summary=$service->summary($rows);
        return view('reports.dispatch-cost-reconciliation',
            compact('from','to','records','rows','summary'));
    }
}
