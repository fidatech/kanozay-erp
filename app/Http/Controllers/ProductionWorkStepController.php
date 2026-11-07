<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Services\ProductionWorkStepService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionWorkStepController extends Controller
{
 public function index(Request $request)
 {
  abort_unless($request->user()->canAny(['manufacturing.view','administration.view']),403);
  $orders=ProductionOrder::with('recipe')->whereIn('status',[
    'planned','reserved','issued','pending_qc','qc_approved','completed',
  ])->latest('id')->limit(200)->get();
  $steps=DB::table('production_work_steps as w')
   ->join('production_orders as p','p.id','=','w.production_order_id')
   ->leftJoin('manufacturing_equipment as e','e.id','=','w.manufacturing_equipment_id')
   ->select('w.*','p.number as order_number','p.status as order_status','e.code as equipment_code')
   ->orderByDesc('p.id')->orderBy('w.step')->paginate(50);
  $byOrder=DB::table('production_work_steps')
   ->select('production_order_id')
   ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as complete_count")
   ->selectRaw('COUNT(*) as total_count')
   ->groupBy('production_order_id')->get()->keyBy('production_order_id');
  return view('production.work-steps',compact('orders','steps','byOrder'));
 }

 public function advance(Request $request,int $step,ProductionWorkStepService $service)
 {
  abort_unless($request->user()->canAny(['manufacturing.update','administration.update']),403);
  $data=$request->validate([
   'action'=>['required',Rule::in(['start','complete'])],
   'notes'=>['nullable','required_if:action,complete','string','max:3000'],
  ]);
  $service->advance($step,$data['action'],$data['notes']??null,$request->user()->id);
  return back()->with('success','Work order stage updated and recorded in the production audit.');
 }
}
