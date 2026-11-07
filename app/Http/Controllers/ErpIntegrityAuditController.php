<?php
namespace App\Http\Controllers;
use App\Services\ErpIntegrityAuditService;
use Illuminate\Http\Request;
class ErpIntegrityAuditController extends Controller
{
 public function index(Request $request,ErpIntegrityAuditService $service)
 {
  abort_unless($request->user()->can('administration.view'),403);
  return view('admin.integrity-audit',['result'=>$service->check()]);
 }
 public function export(Request $request,ErpIntegrityAuditService $service)
 {
  abort_unless($request->user()->can('administration.view'),403);
  $result=$service->check(1000);
  return response()->streamDownload(function()use($result){
   $stream=fopen('php://output','w');
   fputcsv($stream,['Module','Severity','Record','Diagnostic']);
   foreach($result['issues'] as $issue){
    fputcsv($stream,array_map(function($x){
     $v=(string)$x;return preg_match('/^\s*[=+\-@]/u',$v)?"'".$v:$v;
    },array_values($issue)));
   }fclose($stream);
  },'kanozay-integrity-check-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}