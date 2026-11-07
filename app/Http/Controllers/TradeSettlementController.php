<?php
namespace App\Http\Controllers;
use App\Models\TradeDocument;
use App\Models\MoneyTransaction;
use App\Models\TradeSettlementAllocation;
use App\Services\Accounting\TradeSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class TradeSettlementController extends Controller {
 public function store(Request $request,TradeSettlementService $service){
  abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);
  $amount=$request->input('amount');
  if(is_string($amount)){
   $amount=strtr($amount,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٫'=>'.','٬'=>',','،'=>',']);
   $request->merge(['amount'=>str_replace(',','',$amount)]);
  }
  $data=$request->validate(['trade_document_id'=>['required','integer','exists:trade_documents,id'],'money_transaction_id'=>['required','integer','exists:money_transactions,id'],'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],'return_to_party'=>['nullable','boolean']]);
  $document=TradeDocument::findOrFail($data['trade_document_id']);
  $service->allocate((int)$data['trade_document_id'],(int)$data['money_transaction_id'],(string)$data['amount'],$request->user()->id);
  if(!empty($data['return_to_party'])){
   return redirect()->route($document->type==='receivable'?'finance.customers.show':'finance.suppliers.show',$document->trade_party_id)
    ->with('status','Existing payment allocated to the selected invoice.');
  }
  return redirect()->route('finance.documents.index',['type'=>$document->type])->with('status','Settlement allocated successfully.');
 }
}
