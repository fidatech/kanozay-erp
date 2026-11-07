<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\PurchaseOrder;
use App\Models\TradeDocument;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierAdvanceController extends Controller
{
 private function guard(Request $request,bool $write=false):void
 {
  abort_unless($write
    ?($request->user()->can('administration.update') ||
       ($request->user()->can('purchases.update')&&$request->user()->can('finance.update')))
    :$request->user()->canAny(['purchases.view','finance.view','administration.view']),403);
 }

 public function index(Request $request,SupplierAdvanceService $service)
 {
  $this->guard($request);
  $advances=DB::table('supplier_advance_postings as a')
   ->join('purchase_orders as p','p.id','=','a.purchase_order_id')
   ->join('trade_parties as s','s.id','=','a.supplier_id')
   ->join('currencies as c','c.id','=','a.currency_id')
   ->select('a.*','p.number as po_number','s.name as supplier_name','c.code as currency_code')
   ->orderByDesc('a.id')->paginate(25);
  foreach($advances as $advance)$advance->remaining=(string)$service->remaining($advance->id)->toScale(4);
  $applications=DB::table('supplier_advance_applications as a')
   ->join('supplier_advance_postings as p','p.id','=','a.supplier_advance_posting_id')
   ->join('trade_documents as d','d.id','=','a.trade_document_id')
   ->select('a.*','p.number as advance_number','d.number as payable_number')
   ->orderByDesc('a.id')->limit(100)->get();
  $orders=PurchaseOrder::with(['supplier','currency'])->where('status','confirmed')->latest('id')->limit(500)->get();
  $accounts=FinancialAccount::with('currency')->where('is_active',true)->orderBy('name')->get();
  $ledgers=LedgerAccount::where('type','asset')->where('is_active',true)->where('is_group',false)->orderBy('code')->get();
  $payables=TradeDocument::with(['party','currency'])->where('type','payable')
   ->orderByDesc('id')->limit(500)->get();
  return view('purchases.supplier-advances',compact('advances','applications','orders','accounts','ledgers','payables'));
 }

 public function pay(Request $request,SupplierAdvanceService $service)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'purchase_order_id'=>['required',Rule::exists('purchase_orders','id')->where('status','confirmed')],
   'submission_token'=>['required','uuid'],
   'source_account_id'=>['required',Rule::exists('financial_accounts','id')->where('is_active',true)],
   'advance_ledger_id'=>['required','exists:ledger_accounts,id'],
   'paid_on'=>['required','date','before_or_equal:today'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'reference'=>['required','string','min:3','max:120'],
  ]);
  $service->pay(PurchaseOrder::findOrFail($data['purchase_order_id']),$data,$request->user()->id);
  return redirect()->route('workspace.page',['purchases','supplier-advances'])
   ->with('success','Supplier advance payment posted to the cash ledger and supplier prepayment asset.');
 }

 public function apply(Request $request,int $advance,SupplierAdvanceService $service)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'trade_document_id'=>['required',Rule::exists('trade_documents','id')->where('type','payable')],
   'applied_on'=>['required','date','before_or_equal:today'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
   'approval_reference'=>['required','string','min:4','max:180'],
  ]);
  $service->apply($advance,$data,$request->user()->id);
  return redirect()->route('workspace.page',['purchases','supplier-advances'])
   ->with('success','Supplier advance applied to payable with a balanced General Ledger journal.');
 }
}
