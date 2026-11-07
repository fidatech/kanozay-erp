<?php
namespace App\Services\Accounting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Models\ActivityLog;
use App\Models\TradeDocument;
use App\Models\MoneyTransaction;
use App\Models\TradeSettlementAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TradeSettlementService {
 public function allocate(int $documentId,int $transactionId,string $amount,int $userId):TradeSettlementAllocation {
  return DB::transaction(function()use($documentId,$transactionId,$amount,$userId){
   $key=TradeDocument::whereKey($documentId)->firstOrFail();
   DB::table('reconciliation_cases')->where('type',$key->type)->where('trade_party_id',$key->trade_party_id)->where('currency_id',$key->currency_id)->lockForUpdate()->first();
   $document=TradeDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
   if ($document->type !== $key->type || $document->trade_party_id !== $key->trade_party_id || $document->currency_id !== $key->currency_id) throw ValidationException::withMessages(['trade_document_id'=>'Document party, type or currency changed during allocation. Please retry.']);
   $transaction=MoneyTransaction::whereKey($transactionId)->lockForUpdate()->firstOrFail();
   if(!in_array($document->type,['receivable','payable'],true))throw ValidationException::withMessages(['trade_document_id'=>'Unsupported document type.']);
   if(($document->type==='receivable'?'receipt':'payment')!==$transaction->type || $document->currency_id!==$transaction->currency_id)throw ValidationException::withMessages(['money_transaction_id'=>'Settlement type or currency does not match the document.']);
   $party=DB::table('entity_ledger_accounts')->where(['entity_type'=>$document->type==='receivable'?'customer':'supplier','entity_id'=>$document->trade_party_id,'currency_id'=>$document->currency_id,'purpose'=>$document->type])->value('ledger_account_id');
   if(!$party || (int)$transaction->counterpart_ledger_account_id!==(int)$party)throw ValidationException::withMessages(['money_transaction_id'=>'Payment must post against the same customer or supplier ledger.']);
   if(TradeSettlementAllocation::where(['trade_document_id'=>$documentId,'money_transaction_id'=>$transactionId])->exists())throw ValidationException::withMessages(['amount'=>'This transaction is already allocated to this document.']);
   $allocation=BigDecimal::of($amount)->toScale(4,RoundingMode::Unnecessary);
   $documentUsed=$this->allocatedAmount('trade_document_id',$documentId);
   $transactionUsed=$this->allocatedAmount('money_transaction_id',$transactionId);
   $documentRemaining=BigDecimal::of((string)$document->amount)->minus($documentUsed);
   foreach ($document->credits as $credit) $documentRemaining=$documentRemaining->minus((string)$credit->amount);
   $transactionRemaining=BigDecimal::of((string)$transaction->amount)->minus($transactionUsed);
   if($allocation->compareTo(BigDecimal::zero())<=0 || $allocation->compareTo($documentRemaining)>0 || $allocation->compareTo($transactionRemaining)>0)throw ValidationException::withMessages(['amount'=>'Allocation must be positive and cannot exceed either outstanding balance.']);
   $result=TradeSettlementAllocation::create(['trade_document_id'=>$documentId,'money_transaction_id'=>$transactionId,'amount'=>(string)$allocation,'created_by'=>$userId]);
   ActivityLog::record('trade_settlement.allocated','trade_settlement_allocation',$result->id,
    'Payment '.$transaction->id.' allocated to '.$document->number,
    ['trade_document_id'=>$documentId,'money_transaction_id'=>$transactionId,
     'trade_party_id'=>$document->trade_party_id,'currency_id'=>$document->currency_id,
     'amount'=>(string)$allocation]);
   return $result;
  });
 }

 private function allocatedAmount(string $column,int $id):BigDecimal {
  $total=BigDecimal::zero();
  foreach(TradeSettlementAllocation::where($column,$id)->pluck('amount') as $amount)$total=$total->plus((string)$amount);
  return $total;
 }
}
