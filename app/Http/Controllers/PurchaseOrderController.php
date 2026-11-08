<?php
namespace App\Http\Controllers;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Models\{PurchaseOrder,TradeParty,Currency,ActivityLog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class PurchaseOrderController extends Controller {
 private function normalizeAmount($value):mixed {
  if(!is_string($value))return $value;
  $value=strtr($value,[
   '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
   '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
   '٫'=>'.','٬'=>',','،'=>',',
  ]);
  return str_replace(',','',$value);
 }
 private function normalizeItemAmounts(Request $request):void {
  $items=$request->input('items');
  if(!is_array($items))return;
  foreach($items as &$item){
   if(!is_array($item))continue;
   foreach(['quantity','unit_price'] as $field){
    if(array_key_exists($field,$item))$item[$field]=$this->normalizeAmount($item[$field]);
   }
  }
  unset($item);
  $request->merge(['items'=>$items]);
 }
 public function index(Request $request) {
  abort_unless($request->user()->canAny(['administration.view', 'purchases.view']),403);
  $orders=PurchaseOrder::with(['supplier','currency','items'])->latest('id')->paginate(20);
  foreach($orders as $order){$total=BigDecimal::zero();foreach($order->items as $item)$total=$total->plus(BigDecimal::of((string)$item->quantity)->multipliedBy((string)$item->unit_price)->toScale(4,RoundingMode::HalfUp));$order->setAttribute('order_total',(string)$total);}
  return view('purchases.orders',[
   'orders'=>$orders,
   'materials'=>\App\Models\InventoryMaterial::where('is_active',true)->orderBy('name')->get(), 'warehouses'=>\App\Models\CompanyWarehouse::where('is_active',true)->orderBy('name')->get(),
   'materialOptions'=>\App\Models\InventoryMaterial::where('is_active',true)->orderBy('name')->get(['id','sku','name','unit'])->map(fn($m)=>['id'=>$m->id,'label'=>sprintf('%s · %s (%s)', $m->sku, $m->name, $m->unit)])->values(),
   'nextNumber'=>$this->nextNumber(),
   'suppliers'=>TradeParty::where('type','supplier')->where('is_active',true)->orderBy('name')->get(),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
  ]);
 }
 public function store(Request $request) {
  abort_unless($request->user()->canAny(['administration.update', 'purchases.create']),403);
  $this->normalizeItemAmounts($request);
  $data=$request->validate([
   'submission_token'=>['nullable','uuid'],

   'supplier_id'=>['required',Rule::exists('trade_parties','id')->where('type','supplier')->where('is_active',true)],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'source'=>['required',Rule::in(['local','foreign'])],
   'order_date'=>['required','date'],'expected_date'=>['nullable','date','after_or_equal:order_date'],
   'notes'=>['nullable','string','max:3000'],
   'items'=>['required','array','min:1','max:100'],
   'items.*.description'=>['required','string','max:255'],
   'items.*.inventory_material_id'=>['nullable',Rule::exists('inventory_materials','id')->where('is_active',true)],
   'items.*.quantity'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'items.*.unit_price'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],
  ]);
  DB::transaction(function()use($data,$request){
   $supplier=TradeParty::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
   if(!empty($data['submission_token']) &&
      PurchaseOrder::where('submission_token',$data['submission_token'])->exists()){
    throw \Illuminate\Validation\ValidationException::withMessages([
     'submission_token'=>'This purchase order has already been submitted. Refresh the form before creating another.',
    ]);
   }
   if(!$supplier->currencies()->whereKey($data['currency_id'])->exists()){
    throw \Illuminate\Validation\ValidationException::withMessages([
     'currency_id'=>'Enable this currency on the selected supplier account before creating the purchase order.',
    ]);
   }
   $order=PurchaseOrder::create([
    'submission_token'=>$data['submission_token']??null,
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
  abort_unless($request->user()->canAny(['administration.update', 'purchases.create']),403);
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
 public function quickCustomer(Request $request) {
  abort_unless($request->user()->canAny(['administration.update', 'purchases.create']),403);
  $data=$request->validate(['name'=>['required','string','max:180'],'phone'=>['nullable','string','max:80'],'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)]]);
  $party=DB::transaction(function()use($data,$request){
   $party=TradeParty::create(['type'=>'customer','name'=>$data['name'],'phone'=>$data['phone']??null,'is_active'=>true,'created_by'=>$request->user()->id]);
   $party->currencies()->attach($data['currency_id']);
   $party->provisionLedgerAccountsForCurrency((int)$data['currency_id']);
   ActivityLog::record('trade_party.created','trade_party',$party->id,'Customer created from quick-create: '.$party->name);
   return $party;
  });
  return response()->json(['id'=>$party->id,'text'=>$party->name],201);
 }
 public function quickCurrency(Request $request) {
  abort_unless($request->user()->canAny(['administration.update', 'purchases.create']),403);
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
  abort_unless($request->user()->canAny(['administration.view', 'purchases.view']),403);
  $order->load(['supplier','currency','items.inventoryMaterial','items.receiptLines',
    'expenses','advances','receipts.lines']);
  $allReceiptLineIds=$order->receipts->flatMap(fn($receipt)=>$receipt->lines->pluck('id'))->all();
  $costLayers=\App\Models\InventoryCostLayer::where('source_type','goods_receipt_line')
    ->whereIn('source_id',$allReceiptLineIds)->get()->keyBy('source_id');
  $qcService=app(\App\Services\QualityMaterialHoldService::class);
  $receiptQcSummary=[];
  foreach($order->receipts as $grn){
   $heldLines=0;$missingLayers=0;
   foreach($grn->lines as $grnLine){
    $costLayer=$costLayers->get($grnLine->id);
    if(!$costLayer){$missingLayers++;continue;}
    if($qcService->isLayerHeld($costLayer))$heldLines++;
   }
   $receiptQcSummary[$grn->id]=['held_lines'=>$heldLines,'missing_layers'=>$missingLayers];
  }
  $matchedInvoices=\App\Models\MatchedSupplierInvoice::with(['payable.allocations','payable.credits'])
    ->where('purchase_order_id',$order->id)->where('status','posted')->orderByDesc('id')->get();
  $invoiced=BigDecimal::zero();$outstanding=BigDecimal::zero();
  foreach($matchedInvoices as $invoice){
   $invoiced=$invoiced->plus((string)$invoice->total);
   if(!$invoice->payable)continue;
   $balance=BigDecimal::of((string)$invoice->payable->amount);
   foreach($invoice->payable->allocations as $allocation)$balance=$balance->minus((string)$allocation->amount);
   foreach($invoice->payable->credits as $credit)$balance=$balance->minus((string)$credit->amount);
   if($balance->isPositive())$outstanding=$outstanding->plus($balance);
  }
  $advanceRecords=DB::table('supplier_advance_postings')->where('purchase_order_id',$order->id)->get();
  $unappliedAdvances=BigDecimal::zero();
  $applicationAmounts=DB::table('supplier_advance_applications')
    ->whereIn('supplier_advance_posting_id',$advanceRecords->pluck('id'))->get()->groupBy('supplier_advance_posting_id');
  foreach($advanceRecords as $advance){
   $left=BigDecimal::of((string)$advance->amount);
   foreach($applicationAmounts->get($advance->id,collect()) as $application)$left=$left->minus((string)$application->amount);
   if($left->isPositive())$unappliedAdvances=$unappliedAdvances->plus($left);
  }
  return view('purchases.show', [
   'order'=>$order,
   'materials'=>\App\Models\InventoryMaterial::where('is_active',true)->orderBy('name')->get(),
   'warehouses'=>\App\Models\CompanyWarehouse::where('is_active',true)->orderBy('code')->get(),
   'matchedInvoices'=>$matchedInvoices,
   'receiptQcSummary'=>$receiptQcSummary,
   'purchaseFinanceSnapshot'=>[
    'invoiced'=>(string)$invoiced->toScale(4),
    'outstanding'=>(string)$outstanding->toScale(4),
    'unapplied_advances'=>(string)$unappliedAdvances->toScale(4),
   ],
  ]);
 }
 public function updateDraft(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->canAny(['administration.update','purchases.update']),403);
  $data=$request->validate([
   'source'=>['required',Rule::in(['local','foreign'])],
   'order_date'=>['required','date'],
   'expected_date'=>['nullable','date','after_or_equal:order_date'],
   'notes'=>['nullable','string','max:3000'],
  ]);
  DB::transaction(function()use($order,$data){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'Only draft orders may be edited.');
   $locked->update([
    'source'=>$data['source'],'order_date'=>$data['order_date'],
    'expected_date'=>$data['expected_date']??null,'notes'=>$data['notes']??null,
   ]);
   ActivityLog::record('purchase_order.updated','purchase_order',$locked->id,'Draft purchase order header updated.');
  });
  return back()->with('success','Draft purchase order updated.');
 }
 public function updateDraftItem(Request $request,PurchaseOrder $order,\App\Models\PurchaseOrderItem $item) {
  abort_unless($request->user()->canAny(['administration.update','purchases.update']),403);
  abort_unless((int)$item->purchase_order_id===(int)$order->id,404);
  $request->merge([
   'quantity'=>$this->normalizeAmount($request->input('quantity')),
   'unit_price'=>$this->normalizeAmount($request->input('unit_price')),
  ]);
  $data=$request->validate([
   'description'=>['required','string','max:255'],
   'inventory_material_id'=>['required','integer',Rule::exists('inventory_materials','id')->where('is_active',true)],
   'quantity'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'unit_price'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],
  ]);
  DB::transaction(function()use($order,$item,$data){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'A confirmed purchase order cannot be edited.');
   $lockedItem=$locked->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
   if($lockedItem->receiptLines()->exists())
    throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'A received item cannot be edited.']);
   $lockedItem->update($data);
   ActivityLog::record('purchase_order.item_updated','purchase_order',$locked->id,'Draft line '.$lockedItem->id.' updated.');
  });
  return back()->with('success','Purchase order line updated.');
 }
 public function addDraftItem(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->canAny(['administration.update','purchases.update']),403);
  $request->merge([
   'quantity'=>$this->normalizeAmount($request->input('quantity')),
   'unit_price'=>$this->normalizeAmount($request->input('unit_price')),
  ]);
  $data=$request->validate([
   'description'=>['required','string','max:255'],
   'inventory_material_id'=>['required','integer',Rule::exists('inventory_materials','id')->where('is_active',true)],
   'quantity'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'unit_price'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],
  ]);
  DB::transaction(function()use($order,$data){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'A confirmed purchase order cannot gain lines.');
   if($locked->items()->count()>=100)
    throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'A purchase order supports at most 100 lines.']);
   $item=$locked->items()->create($data);
   ActivityLog::record('purchase_order.item_added','purchase_order',$locked->id,'Draft line '.$item->id.' added.');
  });
  return back()->with('success','Purchase order line added.');
 }
 public function removeDraftItem(Request $request,PurchaseOrder $order,\App\Models\PurchaseOrderItem $item) {
  abort_unless($request->user()->canAny(['administration.update','purchases.update']),403);
  abort_unless((int)$item->purchase_order_id===(int)$order->id,404);
  DB::transaction(function()use($order,$item){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'Only draft purchase order lines can be removed.');
   $lockedItem=$locked->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
   if($lockedItem->receiptLines()->exists())
    throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'Received purchase order lines cannot be removed.']);
   try {
    $lockedItem->delete();
   } catch(\Illuminate\Database\QueryException $error){
    throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'This line is referenced by another workflow and cannot be removed.']);
   }
   ActivityLog::record('purchase_order.item_removed','purchase_order',$locked->id,'Unused draft line '.$item->id.' removed.');
  });
  return back()->with('success','Draft purchase order line removed.');
 }
 public function cancel(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->canAny(['administration.update','purchases.update']),403);
  $data=$request->validate(['reason'=>['required','string','min:10','max:1000']]);
  DB::transaction(function()use($order,$data){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless(in_array($locked->status,['draft','confirmed'],true),422,
    'Only unreceived draft or confirmed purchase orders can be cancelled.');
   if($locked->receipts()->exists() ||
      DB::table('supplier_advance_postings')->where('purchase_order_id',$locked->id)->exists()){
    throw \Illuminate\Validation\ValidationException::withMessages([
     'reason'=>'This order has posted receipts or supplier advances and requires a documented correction workflow.',
    ]);
   }
   $locked->update(['status'=>'cancelled']);
   ActivityLog::record('purchase_order.cancelled','purchase_order',$locked->id,
    'Unreceived purchase order cancelled: '.$data['reason']);
  });
  return back()->with('success','Purchase order cancelled. No stock or financial entry was reversed.');
 }
 public function linkMaterial(Request $request, PurchaseOrder $order, \App\Models\PurchaseOrderItem $item) {
  abort_unless($request->user()->can('purchases.update'),403);
  abort_unless($item->purchase_order_id===$order->id,404);
  $data=$request->validate(['inventory_material_id'=>['nullable',Rule::exists('inventory_materials','id')->where('is_active',true)]]);
  DB::transaction(function()use($order,$item,$data){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'Only draft purchase orders can be edited.');
   $lockedItem=$locked->items()->whereKey($item->id)->firstOrFail();
   $lockedItem->update(['inventory_material_id'=>$data['inventory_material_id']??null]);
  });
  return back()->with('success','Purchase item material link saved. No inventory movement occurred.');
 }
 public function confirm(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->can('purchases.update'),403);
  DB::transaction(function()use($order){
   $locked=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='draft',422,'Only draft purchase orders can be confirmed.');
   $items=$locked->items()->with('inventoryMaterial')->lockForUpdate()->get();
   abort_unless($items->isNotEmpty(),422,'Cannot confirm an empty purchase order.');
   if($items->contains(fn($item)=>!$item->inventory_material_id ||
       !$item->inventoryMaterial || !$item->inventoryMaterial->is_active)){
    throw \Illuminate\Validation\ValidationException::withMessages([
     'items'=>'Link every purchase order item to an active inventory material before confirmation.',
    ]);
   }
   $supplier=TradeParty::whereKey($locked->supplier_id)->lockForUpdate()->firstOrFail();
   if(!$supplier->is_active || $supplier->type!=='supplier' ||
       !$supplier->currencies()->whereKey($locked->currency_id)->exists()){
    throw \Illuminate\Validation\ValidationException::withMessages([
     'supplier_id'=>'An active supplier with the purchase currency enabled is required to confirm.',
    ]);
   }
   $locked->update(['status'=>'confirmed']);
   ActivityLog::record('purchase_order.confirmed','purchase_order',$locked->id,
    'Confirmed purchase order with '. $items->count().' linked inventory lines.');
  });
  return back()->with('success','Purchase order confirmed. No stock or finance postings were made.');
 }
 public function addExpense(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->canAny(['administration.update', 'purchases.update']),403);
  abort_unless($order->status==='draft',422,'Only draft orders can be edited.');
  $data=$request->validate([
   'category'=>['required',Rule::in(['transport','loading','customs','insurance','freight','handling','other'])],
   'description'=>['nullable','string','max:255'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'expense_date'=>['required','date'],
  ]);
  $order->expenses()->create($data+['created_by'=>$request->user()->id]);
  return back()->with('success','Purchase expense added.');
 }
 public function addAdvance(Request $request,PurchaseOrder $order) {
  abort_unless($request->user()->canAny(['administration.update', 'purchases.update']),403);
  abort_unless($order->status==='draft',422,'Only draft orders can be edited.');
  $data=$request->validate([
   'payment_date'=>['required','date'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'reference'=>['nullable','string','max:100'],
   'notes'=>['nullable','string','max:2000'],
  ]);
  $order->advances()->create($data+['created_by'=>$request->user()->id]);
  return back()->with('success','Supplier advance recorded on this order (not yet posted to finance).');
 }
}
