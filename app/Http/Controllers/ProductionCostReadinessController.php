<?php
namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Services\ProductionCostReadinessService;
use Illuminate\Http\Request;

class ProductionCostReadinessController extends Controller {
 private function guard(Request $request):void {
  abort_unless($request->user()->canAny([
    'manufacturing.view','finance.view','reports.view','administration.view',
  ]),403);
 }
 public function index(Request $request,ProductionCostReadinessService $service){
  $this->guard($request);
  $filter=$request->validate([
   'status'=>['nullable','in:pending_qc,qc_approved,released,qc_rejected'],
  ]);
  $batches=ProductionBatch::query()->with('costs','finishedGood')
    ->when($filter['status']??null,fn($q,$s)=>$q->where('status',$s))
    ->orderByDesc('id')->paginate(30)->withQueryString();
  return view('production.cost-readiness',[
   'batches'=>$batches,'rows'=>$service->rows($batches->getCollection()),
  ]);
 }
 public function export(Request $request,ProductionCostReadinessService $service){
  $this->guard($request);
  $filter=$request->validate(['status'=>['nullable','in:pending_qc,qc_approved,released,qc_rejected']]);
  return response()->streamDownload(function()use($filter,$service){
    $handle=fopen('php://output','w');
    fputcsv($handle,['Batch','Product','Batch state','Accepted packages',
      'Currency','Material source amount','Approved expense overhead',
      'Unapproved overhead count','Cost complete','Indicative cost per package',
      'Reason/limitation']);
    ProductionBatch::with('costs','finishedGood')
     ->when($filter['status']??null,fn($q,$s)=>$q->where('status',$s))
     ->orderBy('id')->chunkById(150,function($batches)use($service,$handle){
       foreach($service->rows($batches) as $row){
        $cells=[$row['batch'],$row['product'],$row['status'],$row['packages'],
         $row['currency'],$row['material_cost'],$row['approved_overhead'],
         $row['pending_overheads'],$row['ready']?'Reviewed sources':'Blocked',
         $row['cost_per_package'],$row['warning']];
        fputcsv($handle,array_map(function($v){
          $s=(string)($v??'');
          return preg_match('/^\s*[=+\-@]/u',$s)?"'".$s:$s;
        },$cells));
       }
     });
    fclose($handle);
  },'kanozay-production-cost-readiness-'.today()->format('Ymd').'.csv',
    ['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}