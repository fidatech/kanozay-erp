<?php
namespace App\Services\Accounting;
use App\Models\TradeDocument;
use App\Models\MoneyTransaction;
use App\Models\TradeSettlementAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TradeSettlementService {
 public function allocate(int $documentId,int $transactionId,string $amount,int $userId):TradeSettlementAllocation {
  return DB::transaction(function()use($documentId,$transactionId,$amount,$userId){
   $document=TradeDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
   $transaction=MoneyTransaction::whereKey($transactionId)->lockForUpdate()->firstOrFail();
   if(($document->type==='receivable'?'receipt':'payment')!==$transaction->type || $document->currency_id!==$transaction->currency_id)throw ValidationException::withMessages(['money_transaction_id'=>'Settlement type or currency does not match the document.']);
   $party=DB::table('entity_ledger_accounts')->where(['entity_type'=>$document->type==='receivable'?'customer':'supplier','entity_id'=>$document->trade_party_id,'currency_id'=>$document->currency_id,'purpose'=>$document->type])->value('ledger_account_id');
   if(!$party || (int)$transaction->counterpart_ledger_account_id!==(int)$party)throw ValidationException::withMessages(['money_transaction_id'=>'Payment must post against the same customer or supplier ledger.']);
   if(TradeSettlementAllocation::where(['trade_document_id'=>$documentId,'money_transaction_id'=>$transactionId])->exists())throw ValidationException::withMessages(['amount'=>'This transaction is already allocated to this document.']);
   $cents=(int)round((float)$amount*10000);
   $documentUsed=(int)round((float)TradeSettlementAllocation::where('trade_document_id',$documentId)->sum('amount')*10000);
   $transactionUsed=(int)round((float)TradeSettlementAllocation::where('money_transaction_id',$transactionId)->sum('amount')*10000);
   if($cents<=0 || $cents>(int)round((float)$document->amount*10000)-$documentUsed || $cents>(int)round((float)$transaction->amount*10000)-$transactionUsed)throw ValidationException::withMessages(['amount'=>'Allocation must be positive and cannot exceed either outstanding balance.']);
   return TradeSettlementAllocation::create(['trade_document_id'=>$documentId,'money_transaction_id'=>$transactionId,'amount'=>$amount,'created_by'=>$userId]);
  });
 }
}
