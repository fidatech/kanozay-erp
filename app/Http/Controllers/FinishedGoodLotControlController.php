<?php

namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Services\FinishedGoodLotControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinishedGoodLotControlController extends Controller
{
 private function guard(Request $r,bool $write=false):void
 {
  abort_unless($write?($r->user()->can('administration.update') ||
    ($r->user()->can('laboratory.update')&&$r->user()->can('inventory.update')))
    :$r->user()->canAny(['inventory.view','laboratory.view','sales.view','administration.view']),403);
 }

 public function index(Request $r)
 {
  $this->guard($r);
  $batches=ProductionBatch::with('finishedGood')->where('status','released')
    ->orderByDesc('id')->limit(500)->get();
  $lots=DB::table('finished_good_lot_controls as c')
    ->join('production_batches as b','b.id','=','c.production_batch_id')
    ->join('finished_goods as f','f.id','=','b.finished_good_id')
    ->select('c.*','b.batch_number','b.accepted_packages','f.sku','f.name as product_name')
    ->orderByDesc('c.id')->paginate(30);
  $events=DB::table('finished_good_lot_control_events as e')
    ->join('finished_good_lot_controls as c','c.id','=','e.finished_good_lot_control_id')
    ->join('production_batches as b','b.id','=','c.production_batch_id')
    ->select('e.*','b.batch_number')->orderByDesc('e.id')->limit(150)->get();
  return view('inventory.finished-good-lots',compact('batches','lots','events'));
 }

 public function register(Request $r,FinishedGoodLotControlService $service)
 {
  $this->guard($r,true);
  $data=$r->validate([
   'production_batch_id'=>['required','integer',Rule::exists('production_batches','id')->where('status','released')],
   'manufactured_on'=>['nullable','date','before_or_equal:today'],
   'expires_on'=>['nullable','date'],
   'traceability_reference'=>['nullable','string','max:160'],
   'risk_notes'=>['nullable','string','max:3000'],
   'authorization_ref'=>['required','string','min:3','max:180'],
  ]);
  $service->register(ProductionBatch::findOrFail($data['production_batch_id']),$data,$r->user()->id);
  return back()->with('success','Finished good lot control registered. Restricted batches cannot be dispatched.');
 }

 public function decision(Request $r,ProductionBatch $batch,FinishedGoodLotControlService $service)
 {
  $this->guard($r,true);
  $data=$r->validate([
   'action'=>['required',Rule::in(['hold','release','recall'])],
   'reason'=>['required','string','min:10','max:3000'],
   'authorization_ref'=>['required','string','min:3','max:180'],
  ]);
  $service->decide($batch,$data,$r->user()->id);
  return back()->with('success','Lot control decision recorded with immutable audit history.');
 }
}
