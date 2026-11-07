<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\OperationalRegister;
use App\Models\PurchaseOrder;
use App\Models\TradeParty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierQuotationConversionController extends Controller
{
 public function store(Request $request,OperationalRegister $record)
 {
  abort_unless($request->user()->canAny(['purchases.create','administration.update']),403);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'source'=>['required',Rule::in(['local','foreign'])],
   'order_date'=>['required','date','before_or_equal:today'],
   'expected_date'=>['nullable','date','after_or_equal:order_date'],
   'notes'=>['nullable','string','max:2000'],
  ]);
  $order=DB::transaction(function()use($request,$record,$data){
   $locked=OperationalRegister::whereKey($record->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->workflow==='purchases.quotations',404);
   $exists=DB::table('supplier_quotation_conversions')
    ->where('operational_register_id',$locked->id)->first();
   if($exists){
    if($exists->submission_token===$data['submission_token'])
     return PurchaseOrder::findOrFail($exists->purchase_order_id);
    throw ValidationException::withMessages(['record'=>'Quotation already converted to a purchase order.']);
   }
   if($locked->status!=='approved')
    throw ValidationException::withMessages(['record'=>'An independently approved supplier quotation is required.']);
   $quote=$locked->data;
   if($quote['valid_until']<$data['order_date'])
    throw ValidationException::withMessages(['order_date'=>'Supplier offer has expired. Obtain renewed quotation approval.']);
   $supplier=TradeParty::whereKey($quote['supplier'])->where('type','supplier')
    ->where('is_active',true)->lockForUpdate()->first();
   $material=InventoryMaterial::whereKey($quote['material'])->where('is_active',true)->first();
   $currency=Currency::whereKey($quote['currency'])->where('is_active',true)->first();
   if(!$supplier||!$material||!$currency||!$supplier->currencies()->whereKey($currency->id)->exists())
    throw ValidationException::withMessages(['record'=>'The supplier, material or quotation currency is inactive.']);
   $quantity=BigDecimal::of((string)$quote['quantity']);
   $price=BigDecimal::of((string)$quote['unit_price']);
   if(!$quantity->isPositive()||!$price->isPositive())
    throw ValidationException::withMessages(['record'=>'Quotation quantity and price must be positive.']);
   if(!$quantity->isEqualTo($quantity->toScale(4,RoundingMode::Down)) ||
      !$price->isEqualTo($price->toScale(4,RoundingMode::Down)))
    throw ValidationException::withMessages(['record'=>'Quotation precision exceeds the four-decimal purchase-order limit. Correct the source quotation before approval.']);
   if($quantity->isGreaterThan('999999999999.9999')||$price->isGreaterThan('999999999999.9999'))
    throw ValidationException::withMessages(['record'=>'Quoted unit price or quantity exceeds purchase-order bounds.']);
   $order=PurchaseOrder::create([
    'number'=>'TMP-'.Str::uuid(),'supplier_id'=>$supplier->id,
    'currency_id'=>$currency->id,'source'=>$data['source'],
    'order_date'=>$data['order_date'],'expected_date'=>$data['expected_date']??null,
    'status'=>'draft',
    'notes'=>'Approved supplier quotation '.$locked->number.' | '.$quote['reference'].PHP_EOL.
      ($quote['terms']??'').PHP_EOL.($data['notes']??''),
    'created_by'=>$request->user()->id,
   ]);
   $order->update(['number'=>'PO-'.str_pad((string)$order->id,6,'0',STR_PAD_LEFT)]);
   $order->items()->create([
    'description'=>$material->name.' - supplier offer '.$quote['reference'],
    'inventory_material_id'=>$material->id,
    'quantity'=>(string)$quantity->toScale(4),
    'unit_price'=>(string)$price->toScale(4),
   ]);
   DB::table('supplier_quotation_conversions')->insert([
    'operational_register_id'=>$locked->id,'purchase_order_id'=>$order->id,
    'submission_token'=>$data['submission_token'],
    'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   $locked->update(['status'=>'closed','closed_by'=>$request->user()->id,'closed_at'=>now()]);
   $locked->events()->create([
    'action'=>'converted','note'=>'Created draft purchase order '.$order->number,
    'snapshot'=>$locked->data,'user_id'=>$request->user()->id,
   ]);
   ActivityLog::record('supplier.quotation.converted','operational_register',$locked->id,
    'Approved supplier quotation converted to draft purchase order '.$order->number,
    ['purchase_order_id'=>$order->id]);
   return $order;
  });
  return redirect()->route('purchases.orders.show',$order)
   ->with('success','Draft purchase order created from approved quotation; confirm it before receiving stock.');
 }
}
