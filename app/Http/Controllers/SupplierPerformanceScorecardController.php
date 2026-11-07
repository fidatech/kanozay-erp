<?php
namespace App\Http\Controllers;

use App\Models\OperationalRegister;
use App\Models\TradeParty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPerformanceScorecardController extends Controller
{
 private function report(Request $request):array
 {
  abort_unless($request->user()->canAny(['purchases.view','administration.view']),403);
  $filter=$request->validate([
   'supplier_id'=>['nullable','integer','exists:trade_parties,id'],
  ]);
  $suppliers=TradeParty::where('type','supplier')
   ->when(!empty($filter['supplier_id']),fn($q)=>$q->whereKey($filter['supplier_id']))
   ->orderBy('name')->limit(250)->get(['id','name','code','is_active']);
  $ids=$suppliers->pluck('id');
  $purchases=DB::table('purchase_orders')
   ->whereIn('supplier_id',$ids)
   ->select('supplier_id')->selectRaw('COUNT(*) as order_count')
   ->selectRaw("SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders")
   ->groupBy('supplier_id')->get()->keyBy('supplier_id');
  $receipts=DB::table('goods_receipts as g')
   ->join('purchase_orders as p','p.id','=','g.purchase_order_id')
   ->whereIn('p.supplier_id',$ids)->where('g.status','posted')
   ->select('p.supplier_id')
   ->selectRaw('COUNT(*) as receipt_count')
   ->selectRaw('SUM(CASE WHEN p.expected_date IS NOT NULL THEN 1 ELSE 0 END) as dated_receipts')
   ->selectRaw('SUM(CASE WHEN p.expected_date IS NOT NULL AND g.received_on <= p.expected_date THEN 1 ELSE 0 END) as on_time_receipts')
   ->groupBy('p.supplier_id')->get()->keyBy('supplier_id');
  $returns=DB::table('purchase_return_records as r')
   ->join('purchase_orders as p','p.id','=','r.purchase_order_id')
   ->whereIn('p.supplier_id',$ids)
   ->select('p.supplier_id')->selectRaw('COUNT(*) as return_count')
   ->groupBy('p.supplier_id')->get()->keyBy('supplier_id');
  $invoices=DB::table('matched_supplier_invoices')
   ->whereIn('supplier_id',$ids)
   ->select('supplier_id')->selectRaw('COUNT(*) as matched_invoice_count')
   ->groupBy('supplier_id')->get()->keyBy('supplier_id');
  $manual=OperationalRegister::where('workflow','purchases.supplier-performance')
   ->whereIn('status',['approved','closed'])->orderByDesc('id')->get()
   ->groupBy(fn($record)=>(int)($record->data['supplier']??0));
  $rows=$suppliers->map(function($supplier)use($purchases,$receipts,$returns,$invoices,$manual){
   $o=$purchases->get($supplier->id);$r=$receipts->get($supplier->id);
   $review=$manual->get($supplier->id)?->first();
   $evaluation=$review?->data;
   return [
    'supplier_id'=>$supplier->id,'name'=>$supplier->name,'code'=>$supplier->code,
    'is_active'=>$supplier->is_active,
    'orders'=>(int)($o?->order_count??0),
    'confirmed_orders'=>(int)($o?->confirmed_orders??0),
    'receipts'=>(int)($r?->receipt_count??0),
    'dated_receipts'=>(int)($r?->dated_receipts??0),
    'on_time'=>(int)($r?->on_time_receipts??0),
    'returns'=>(int)($returns->get($supplier->id)?->return_count??0),
    'matched_invoices'=>(int)($invoices->get($supplier->id)?->matched_invoice_count??0),
    'review_no'=>$review?->number,
    'review_quality'=>$evaluation['quality_score']??null,
    'review_delivery'=>$evaluation['delivery_score']??null,
    'review_period'=>$evaluation['period_end']??null,
   ];
  });
  return ['rows'=>$rows,'supplier_id'=>$filter['supplier_id']??null];
 }
 public function index(Request $request)
 {
  return view('purchases.supplier-scorecards',$this->report($request));
 }
 public function export(Request $request)
 {
  $data=$this->report($request);
  return response()->streamDownload(function()use($data){
   $stream=fopen('php://output','w');
   fputcsv($stream,['Supplier','Purchase Orders','Confirmed Orders','GRNs Posted','GRNs With Expected Date',
    'GRNs Received On Time','Supplier Returns','Matched Supplier Invoices','Reviewed Quality Score','Reviewed Delivery Score','Reviewed Through']);
   foreach($data['rows'] as $row){
    $cells=[$row['name'],$row['orders'],$row['confirmed_orders'],$row['receipts'],
     $row['dated_receipts'],$row['on_time'],$row['returns'],$row['matched_invoices'],
     $row['review_quality'],$row['review_delivery'],$row['review_period']];
    fputcsv($stream,array_map(fn($s)=>preg_match('/^\s*[=+\-@]/u',(string)$s)?"'".$s:$s,$cells));
   }fclose($stream);
  },'kanozay-supplier-scorecards-'.today()->format('Ymd').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}