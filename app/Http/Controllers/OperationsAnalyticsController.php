<?php

namespace App\Http\Controllers;

use App\Services\OperationsAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationsAnalyticsController extends Controller
{
    public function index(Request $request,OperationsAnalyticsService $analytics)
    {
        abort_unless($request->user()->canAny(['reports.view','administration.view']),403);
        $data=$request->validate([
            'from'=>['nullable','date'],
            'to'=>['nullable','date','after_or_equal:from'],
            'export'=>['nullable',Rule::in(['csv'])],
        ]);
        $from=$data['from']??now()->startOfMonth()->toDateString();
        $to=$data['to']??now()->toDateString();
        if($from>$to){
            throw \Illuminate\Validation\ValidationException::withMessages([
                'to'=>'The end of the reporting period must not precede its beginning.',
            ]);
        }
        $summary=$analytics->summarize($from,$to);
        if(($data['export']??null)==='csv'){
            return response()->streamDownload(function()use($summary){
                $out=fopen('php://output','w');
                fwrite($out,"\xEF\xBB\xBF");
                fputcsv($out,['Kanozay operational report','From',$summary['from'],'To',$summary['to']]);
                fputcsv($out,['Currency','Posted invoice count','Gross posted invoice face value',
                    'Credit note count','Credit note face value']);
                foreach($summary['currencies'] as $row){
                    fputcsv($out,[
                        ReportCenterController::csvSafe($row['currency']),
                        $row['invoices'],$row['invoice_total'],
                        $row['credits'],$row['credit_total'],
                    ]);
                }
                fputcsv($out,[]);
                fputcsv($out,['Operational metric','Recorded count']);
                foreach($summary['operations'] as $label=>$amount){
                    fputcsv($out,[$label,$amount]);
                }
                fputcsv($out,[]);
                fputcsv($out,['QC source','Samples collected','Approved','Rejected','Pending']);
                foreach($summary['samples'] as $source=>$row){
                    fputcsv($out,[$source,$row['collected'],$row['approved'],
                        $row['rejected'],$row['pending']]);
                }
                fclose($out);
            },'kanozay-operations-'.$from.'-'.$to.'.csv',
              ['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        return view('reports.overview',compact('summary'));
    }
}
