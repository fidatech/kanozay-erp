<?php
namespace App\Http\Controllers;

use App\Models\LedgerAccount;
use App\Models\PurchaseReturnRecord;
use App\Models\SupplierDebitNote;
use App\Models\TradeDocument;
use App\Services\SupplierDebitNoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierDebitNoteController extends Controller {
 public function index(Request $request){
  abort_unless($request->user()->canAny(['purchases.view','finance.view','administration.view']),403);
  $notes=SupplierDebitNote::with(['purchaseReturn','document.party','currency','journal'])
    ->orderByDesc('id')->paginate(25);
  $returns=PurchaseReturnRecord::with(['order.supplier','currency','lines'])
    ->where('financial_status','supplier_credit_pending')->latest('id')->limit(300)->get();
  $payables=TradeDocument::with('party')->where('type','payable')->latest('id')->limit(500)->get();
  $offsetLedgers=LedgerAccount::whereIn('type',['asset','expense'])
    ->where('is_group',false)->where('is_active',true)->whereNotIn('id',
      \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->select('ledger_account_id'))
    ->orderBy('code')->get();
  return view('purchases.supplier-debit-notes',compact('notes','returns','payables','offsetLedgers'));
 }
 public function post(Request $request,PurchaseReturnRecord $return,SupplierDebitNoteService $service){
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'trade_document_id'=>['required','integer',Rule::exists('trade_documents','id')->where('type','payable')],
   'credit_offset_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
   'credit_date'=>['required','date','before_or_equal:today'],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:99999999999999999999.9999'],
   'supplier_credit_reference'=>['required','string','min:3','max:180'],
   'review_notes'=>['required','string','min:10','max:3000'],
  ]);
  $note=$service->post($return,$data,$request->user()->id);
  return redirect()->route('purchases.supplier-debit-notes.index')
   ->with('success','Supplier debit note '.$note->number.' posted and the payable balance reduced.');
 }
}
