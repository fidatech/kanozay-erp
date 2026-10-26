<?php
namespace App\Http\Controllers;
use App\Models\{PurchaseOrder,TradeParty,Currency,ActivityLog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class PurchaseOrderController extends Controller {
 public function index(Request $request) {
  abort_unless($request->user()->can('administration.view'),403);
  return view('purchases.orders',[
   'orders'=>PurchaseOrder::with(['supplier','currency','items'])->latest('id')->paginate(20),
   'nextNumber'=>$this->nextNumber(),
   'suppliers'=>TradeParty::where('type','supplier')->where('is_active',true)->orderBy('name')->get(),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
  ]);
 }
 public function store(Request $request) {
  abort_unless($request->user()->can('administration.view'),403);
  $data=$request->validate([

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
    'number'=>'TMP-'.(string) \Illuminate\Support\Str::uuid(),'supplier_id'=>$data['supplier_id'],'currency_id'=>$data['currency_id'],
    'source'=>$data['source'],'order_date'=>$data['order_date'],'expected_date'=>$data['expected_date']??null,
    'notes'=>$data['notes']??null,'created_by'=>$request->user()->id,'status'=>'draft',
   ]);
   $order->update(['number'=>'PO-'.str_pad((string)$order->id,6,'0',STR_PAD_LEFT)]);
   $order->items()->createMany($data['items']);
  });
  return redirect()->route('purchases.orders.index')->with('success','Draft purchase order created.');
 }
 private function nextNumber():string {
  $last=(int) PurchaseOrder::query()->max('id');
  return 'PO-'.str_pad((string)($last+1),6,'0',STR_PAD_LEFT);
 }
 public function quickSupplier(Request $request) {
  abort_unless($request->user()->can('administration.update'),403);
  $data=$request->validate(['name'=>['required','string','max:180'],'phone'=>['nullable','string','max:80'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)]]);
  $party=DB::transaction(function()use($data,$request){
   $party=TradeParty::create(['type'=>'supplier','name'=>$data['name'],'phone'=>$data['phone']??null,'is_active'=>true,'created_by'=>$request->user()->id]);
   $party->currencies()->attach($data['currency_id']);
   $party->provisionLedgerAccountsForCurrency((int)$data['currency_id']);
   ActivityLog::record('trade_party.created','trade_party',$party->id,'Supplier created from purchase order: '.$party->name);
   return $party;
  });
  return response()->json(['id'=>$party->id,'text'=>$party->name],201);
 }
 public function quickCurrency(Request $request) {
  abort_unless($request->user()->can('administration.update'),403);
  $data=$request->validate(['code'=>['required','regex:/^[A-Za-z]{3}$/'],'name'=>['required','string','max:100'],'symbol'=>['nullable','string','max:12']]);
  $code=strtoupper($data['code']);
  if(Currency::where('code',$code)->exists())throw \Illuminate\Validation\ValidationException::withMessages(['code'=>'Currency code already exists.']);
  $currency=DB::transaction(function()use($data,$code){
   $currency=Currency::create(['code'=>$code,'name'=>$data['name'],'symbol'=>$data['symbol']??null,'decimal_places'=>2,'is_active'=>true,'is_base'=>!Currency::where('is_base',true)->exists()]);
   ActivityLog::record('currency.created','currency',$currency->id,'Currency '.$code.' created from purchase order');
   return $currency;
  });
  return response()->json(['id'=>$currency->id,'text'=>$currency->code.' — '.$currency->name],201);
 }
 public function show(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->can('administration.view'),403);
  return view('purchases.show', ['order'=>$order->load(['supplier','currency','items','expenses','advances'])]);
 }
 public function addExpense(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->can('administration.view'),403);
  abort_unless($order->status==='draft',422,'Only draft orders can be edited.');
  $data=$request->validate([
   'category'=>['required',Rule::in(['transport','loading','customs','insurance','freight','handling','other'])],
   'description'=>['nullable','string','max:255'],
   'amount'=>['required','numeric','gt:0','max:999999999999'],
   'expense_date'=>['required','date'],
  ]);
  $order->expenses()->create($data+['created_by'=>$request->user()->id]);
  return back()->with('success','Purchase expense added.');
 }
 public function addAdvance(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->can('administration.view'),403);
  abort_unless($order->status==='draft',422,'Only draft orders can be edited.');
  $data=$request->validate([
   'payment_date'=>['required','date'],
   'amount'=>['required','numeric','gt:0','max:999999999999'],
   'reference'=>['nullable','string','max:100'],
   'notes'=>['nullable','string','max:2000'],
  ]);
  $order->advances()->create($data+['created_by'=>$request->user()->id]);
  return back()->with('success','Supplier advance recorded on this order (not yet posted to finance).');
 }
}
