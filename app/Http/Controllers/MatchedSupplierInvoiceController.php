<?php
namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Support\LocalizedDecimal;
use App\Models\LedgerAccount;
use App\Models\MatchedSupplierInvoice;
use App\Services\MatchedSupplierInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MatchedSupplierInvoiceController extends Controller
{
 private function guard(Request $r,bool $write=false):void
 {
  abort_unless($write
   ?($r->user()->can('administration.update') ||
     ($r->user()->can('purchases.update') && $r->user()->can('finance.update')))
   :$r->user()->canAny(['purchases.view','finance.view','administration.view']),403);
 }
 public function index(Request $request,MatchedSupplierInvoiceService $service)
 {
  $this->guard($request);
  $filters=$request->validate(['receipt_id'=>['nullable','integer','exists:goods_receipts,id']]);
  $receipts=GoodsReceipt::with(['purchaseOrder.supplier','purchaseOrder.currency'])
   ->where('status','posted')->orderByDesc('id')->limit(400)->get();
  $receipt=null;$remaining=[];
  if(!empty($filters['receipt_id'])){
   $receipt=GoodsReceipt::with(['purchaseOrder.supplier','purchaseOrder.currency','lines.material'])
    ->where('status','posted')->findOrFail($filters['receipt_id']);
   $remaining=$service->remaining($receipt);
  }
  return view('purchases.matched-supplier-invoices',[
   'receipts'=>$receipts,'receipt'=>$receipt,'remaining'=>$remaining,
   'ledgers'=>LedgerAccount::where('type','asset')->where('is_active',true)
      ->where('is_group',false)->orderBy('code')->get(),
   'invoices'=>MatchedSupplierInvoice::with(['supplier','receipt','currency','payable'])
      ->orderByDesc('id')->paginate(25)->withQueryString(),
  ]);
 }
 public function store(Request $request,MatchedSupplierInvoiceService $service)
 {
  $this->guard($request,true);
  $request->merge([
   'quantities'=>LocalizedDecimal::quantities($request->input('quantities')),
   'invoice_total'=>LocalizedDecimal::normalize($request->input('invoice_total')),
  ]);
  $data=$request->validate([
   'goods_receipt_id'=>['required',Rule::exists('goods_receipts','id')->where('status','posted')],
   'submission_token'=>['required','uuid'],
   'supplier_invoice_number'=>['required','string','min:2','max:100'],
   'invoice_date'=>['required','date','before_or_equal:today'],
   'due_date'=>['required','date','after_or_equal:invoice_date'],
   'inventory_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
   'invoice_total'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
   'quantities'=>['required','array','min:1','max:100'],
   'quantities.*'=>['nullable','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/'],
  ]);
  $invoice=$service->post(GoodsReceipt::findOrFail($data['goods_receipt_id']),$data,$request->user()->id);
  return redirect()->route('purchases.matched-supplier-invoices.show',$invoice)
   ->with('success','Matched supplier payable and balanced journal posted.');
 }
 public function show(Request $request,MatchedSupplierInvoice $invoice)
 {
  $this->guard($request);
  $invoice->load(['supplier','order','receipt','currency','lines.material','journal.lines','payable']);
  return view('purchases.matched-supplier-invoice-show',compact('invoice'));
 }
}
