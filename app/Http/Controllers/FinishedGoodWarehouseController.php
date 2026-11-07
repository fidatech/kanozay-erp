<?php

namespace App\Http\Controllers;

use App\Models\CompanyWarehouse;
use App\Models\ProductionBatch;
use App\Services\FinishedGoodDispatchService;
use App\Services\FinishedGoodWarehouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinishedGoodWarehouseController extends Controller
{
 private function guard(Request $request,bool $write=false):void
 {
  abort_unless($request->user()->canAny($write
   ?['inventory.update','administration.update']
   :['inventory.view','administration.view']),403);
 }
 public function index(Request $request,FinishedGoodDispatchService $dispatch)
 {
  $this->guard($request);
  $warehouses=CompanyWarehouse::where('is_active',true)->orderBy('code')->get();
  $batches=ProductionBatch::with('finishedGood')
   ->where('status','released')->orderByDesc('id')->limit(700)->get();
  $balances=DB::table('finished_good_warehouse_stocks')
   ->select('production_batch_id','company_warehouse_id','quantity_packages')->get()
   ->groupBy('production_batch_id');
  $stock=[];
  foreach($batches as $batch){
   $rows=$balances->get($batch->id,collect());
   $allocated=0;
   foreach($rows as $row)$allocated+=(int)$row->quantity_packages;
   $available=$dispatch->availablePackages($batch);
   $stock[]=['batch'=>$batch,'assigned'=>$allocated,'unassigned'=>$available-$allocated,
    'available'=>$available,'warehouse'=>$rows->keyBy('company_warehouse_id')];
  }
  $moves=DB::table('finished_good_warehouse_movements as m')
   ->join('production_batches as b','b.id','=','m.production_batch_id')
   ->leftJoin('company_warehouses as s','s.id','=','m.source_warehouse_id')
   ->leftJoin('company_warehouses as d','d.id','=','m.destination_warehouse_id')
   ->select('m.*','b.batch_number','s.name as source_name','d.name as destination_name')
   ->orderByDesc('m.id')->limit(100)->get();
  return view('inventory.finished-good-warehouses',compact('warehouses','stock','moves'));
 }
 public function transfer(Request $request,FinishedGoodWarehouseService $service)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'production_batch_id'=>['required',Rule::exists('production_batches','id')->where('status','released')],
   'source_warehouse_id'=>['nullable',Rule::exists('company_warehouses','id')->where('is_active',true)],
   'destination_warehouse_id'=>['required',Rule::exists('company_warehouses','id')->where('is_active',true)],
   'quantity_packages'=>['required','integer','between:1,2147483647'],
   'occurred_on'=>['required','date','before_or_equal:today'],
   'reason'=>['required','string','min:5','max:160'],
   'submission_token'=>['required','uuid'],
  ]);
  $service->transfer(
    ProductionBatch::findOrFail($data['production_batch_id']),
    $data['source_warehouse_id']??null,(int)$data['destination_warehouse_id'],
    (int)$data['quantity_packages'],$data['occurred_on'],$data['reason'],
    $request->user()->id,$data['submission_token']
  );
  return redirect()->route('inventory.finished-good-warehouses.index')
    ->with('success','Finished goods packages moved without changing the company-wide stock balance.');
 }
}
