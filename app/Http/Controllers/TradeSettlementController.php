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
  abort_unless($request->user()->can('administration.update'),403);
  $data=$request->validate(['trade_document_id'=>['required','integer','exists:trade_documents,id'],'money_transaction_id'=>['required','integer','exists:money_transactions,id'],'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999']]);
  $document=TradeDocument::findOrFail($data['trade_document_id']);
  $service->allocate((int)$data['trade_document_id'],(int)$data['money_transaction_id'],(string)$data['amount'],$request->user()->id);
  return redirect()->route('finance.documents.index',['type'=>$document->type])->with('status','Settlement allocated successfully.');
 }
}
