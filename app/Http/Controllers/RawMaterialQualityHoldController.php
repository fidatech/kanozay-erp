<?php
namespace App\Http\Controllers;
use App\Models\InventoryCostLayer;
use App\Services\QualityMaterialHoldService;
use App\Services\QualityLotManualHoldService;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RawMaterialQualityHoldController extends Controller {
 public function index(Request $request,QualityMaterialHoldService $holds) {
  abort_unless($request->user()->canAny(['inventory.view','laboratory.view','administration.view']),403);
  $layers=InventoryCostLayer::with('material')
   ->where('source_type','goods_receipt_line')->latest('id')->paginate(40);
  $rows=$layers->getCollection()->map(function($layer)use($holds){
   $line=DB::table('goods_receipt_lines as l')
    ->join('goods_receipts as r','r.id','=','l.goods_receipt_id')
    ->where('l.id',$layer->source_id)->select('r.number','l.id')->first();
   $sample=DB::table('quality_samples')->where('goods_receipt_line_id',$layer->source_id)
    ->latest('id')->first();
   $manual=DB::table('quality_material_lot_holds')
    ->where('inventory_cost_layer_id',$layer->id)->where('status','held')
    ->orderByDesc('id')->first();
   return ['layer'=>$layer,'receipt'=>$line?->number,
     'sample'=>$sample,'manual_hold'=>$manual,'held'=>$holds->isLayerHeld($layer)];
  });
  $events=DB::table('quality_material_lot_hold_events as e')
    ->join('quality_material_lot_holds as h','h.id','=','e.quality_material_lot_hold_id')
    ->join('inventory_cost_layers as layer','layer.id','=','h.inventory_cost_layer_id')
    ->join('inventory_materials as material','material.id','=','layer.inventory_material_id')
    ->select('e.*','h.inventory_cost_layer_id','material.sku')
    ->orderByDesc('e.id')->limit(60)->get();
  return view('inventory.quality-holds',compact('layers','rows','events'));
 }

 public function hold(Request $request,InventoryCostLayer $layer,QualityLotManualHoldService $service)
 {
  abort_unless($request->user()->canAny(['laboratory.update','administration.update']),403);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'reference'=>['required','string','min:3','max:180'],
   'reason'=>['required','string','min:10','max:3000'],
  ]);
  $service->hold($layer->id,$data,$request->user()->id);
  return redirect()->route('inventory.quality-holds.index')
    ->with('success','Manual incoming-material QC hold recorded. Related stock remains restricted.');
 }

 public function release(Request $request,int $hold,QualityLotManualHoldService $service)
 {
  abort_unless($request->user()->canAny(['laboratory.update','administration.update']),403);
  $data=$request->validate([
   'reference'=>['required','string','min:3','max:180'],
   'reason'=>['required','string','min:10','max:3000'],
  ]);
  $service->release($hold,$data,$request->user()->id);
  return redirect()->route('inventory.quality-holds.index')
    ->with('success','Independent manual hold release documented. Other QC restrictions still apply.');
 }
}
