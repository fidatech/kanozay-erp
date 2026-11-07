<?php
namespace App\Http\Controllers;
use App\Models\InventoryCostLayer;
use App\Services\QualityMaterialHoldService;
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
   return ['layer'=>$layer,'receipt'=>$line?->number,
     'sample'=>$sample,'held'=>$holds->isLayerHeld($layer)];
  });
  return view('inventory.quality-holds',compact('layers','rows'));
 }
}
