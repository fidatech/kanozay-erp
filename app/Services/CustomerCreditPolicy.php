<?php
namespace App\Services;
use App\Models\TradeDocument;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCreditPolicy
{
 /** Call only inside the sales invoice transaction while its customer row is locked. */
 public function enforce(int $partyId,int $currencyId,BigDecimal $invoiceAmount):void
 {
  $limit=DB::table('customer_credit_limits')
   ->where('trade_party_id',$partyId)->where('currency_id',$currencyId)->where('is_active',true)->first();
  if(!$limit)return;
  $open=BigDecimal::zero();
  foreach(TradeDocument::with(['allocations','credits'])->where('type','receivable')
     ->where('trade_party_id',$partyId)->where('currency_id',$currencyId)->get() as $doc){
   $amount=BigDecimal::of((string)$doc->amount);
   foreach($doc->allocations as $payment)$amount=$amount->minus((string)$payment->amount);
   foreach($doc->credits as $credit)$amount=$amount->minus((string)$credit->amount);
   if($amount->isPositive())$open=$open->plus($amount);
  }
  if($open->plus($invoiceAmount)->isGreaterThan((string)$limit->limit_amount))
   throw ValidationException::withMessages(['invoice'=>
    'Approved credit limit exceeded for the invoice currency. '
    .'Outstanding '.(string)$open->toScale(4).', proposed '.(string)$invoiceAmount->toScale(4)
    .', approved limit '.$limit->limit_amount.'. Record payment or obtain authorization first.']);
 }
}
