<?php
namespace App\Http\Controllers;
use App\Models\{PurchaseOrder,TradeParty,Currency};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class PurchaseOrderController extends Controller {
 public function index(Request $request) {
  abort_unless($request->user()->can('administration.view'),403);
  return view('purchases.orders',[
   'orders'=>PurchaseOrder::with(['supplier','currency','items'])->latest('id')->paginate(20),
   'suppliers'=>TradeParty::where('type','supplier')->where('is_active',true)->orderBy('name')->get(),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
  ]);
 }
 public function store(Request $request) {
  abort_unless($request->user()->can('administration.view'),403);
  $data=$request->validate([
   'number'=>['required','string','max:60','unique:purchase_orders,number'],
   'supplier_id'=>['required',Rule::exists('trade_parties','id')->where('type','supplier')->where('is_active',true)],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'source'=>['required',Rule::in(['local','foreign'])],
   'order_date'=>['required','date'],'expected_date'=>['nullable','date','after_or_equal:order_date'],
   'notes'=>['nullable','string','max:3000'],
   'items'=>['required','array','min:1','max:100'],
   'items.*.description'=>['required','string','max:255'],
   'items.*.quantity'=>['required','numeric','gt:0','max:999999999'],
   'items.*.unit_price'=>['required','numeric','min:0','max:999999999999'],
  ]);
  DB::transaction(function()use($data,$request){
   $order=PurchaseOrder::create([
    'number'=>$data['number'],'supplier_id'=>$data['supplier_id'],'currency_id'=>$data['currency_id'],
    'source'=>$data['source'],'order_date'=>$data['order_date'],'expected_date'=>$data['expected_date']??null,
    'notes'=>$data['notes']??null,'created_by'=>$request->user()->id,'status'=>'draft',
   ]);
   $order->items()->createMany($data['items']);
  });
  return redirect()->route('purchases.orders.index')->with('success','Draft purchase order created.');
 }
 public function show(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->can('administration.view'),403);
  return view('purchases.show', ['order'=>$order->load(['supplier','currency','items'])]);
 }
}
