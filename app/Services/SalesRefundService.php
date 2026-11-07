<?php
namespace App\Services;

use App\Models\{ActivityLog,DocumentSequence,FinancialAccount,JournalEntry,MoneyTransaction,SalesCreditNote,SalesCreditNoteRefund,User};
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesRefundService {
 public function __construct(private readonly JournalPostingService $journals){}
 /**
  * Inside the original credit note transaction, debit customer receivable
  * for the reimbursed portion and credit the mapped source cash/bank ledger.
  * The trade document credit itself only covers its unpaid invoice balance.
  */
 public function post(SalesCreditNote $note,string $amount,int $sourceId,
   string $approvalReference,int $customerLedgerId,int $actorId):SalesCreditNoteRefund
 {
  $actor=User::findOrFail($actorId);
  if(!$actor->canAny(['finance.update','administration.update']))
   throw ValidationException::withMessages(['refund_source_account_id'=>'A finance-authorized user is required to release a cash refund.']);
  $value=BigDecimal::of($amount)->toScale(4);
  if(!$value->isPositive() || $value->isGreaterThan('999999999999.9999'))
   throw ValidationException::withMessages(['refund_source_account_id'=>'Refund amount exceeds supported finance payment range.']);
  $account=FinancialAccount::whereKey($sourceId)->lockForUpdate()->firstOrFail();
  if(!$account->is_active||(int)$account->currency_id!==(int)$note->invoice->currency_id)
   throw ValidationException::withMessages(['refund_source_account_id'=>'Select an active refund account in the invoice currency.']);
  $cashLedger=DB::table('entity_ledger_accounts')->where([
   'entity_type'=>$account->type,'entity_id'=>$account->id,
   'currency_id'=>$note->invoice->currency_id,'purpose'=>'cash',
  ])->value('ledger_account_id');
  if(!$cashLedger||(int)$cashLedger===$customerLedgerId)
   throw ValidationException::withMessages(['refund_source_account_id'=>'The refund source account has no independent cash ledger mapping.']);
  DocumentSequence::firstOrCreate(['document_type'=>'customer_refund'],[
   'prefix'=>'CRF-','next_number'=>1,'padding'=>6,'is_active'=>true,
  ]);
  $number=DocumentSequence::issue('customer_refund');
  $journal=JournalEntry::create([
   'number'=>$number,'entry_date'=>$note->credit_date->toDateString(),
   'reference'=>$note->number,
   'description'=>'Refund for '.$note->number.' authorization '.$approvalReference,
   'currency_id'=>$note->invoice->currency_id,'created_by'=>$actorId,'status'=>'draft',
  ]);
  $journal->lines()->createMany([
   ['ledger_account_id'=>$customerLedgerId,'debit'=>(string)$value,'credit'=>'0','memo'=>'Customer paid-invoice refund'],
   ['ledger_account_id'=>$cashLedger,'debit'=>'0','credit'=>(string)$value,'memo'=>'Refund through cash/bank'],
  ]);
  $this->journals->post($journal,$actorId);
  $payment=MoneyTransaction::create([
   'submission_token'=>(string)\Illuminate\Support\Str::uuid(),'type'=>'payment',
   'transaction_date'=>$note->credit_date->toDateString(),'source_account_id'=>$account->id,
   'destination_account_id'=>null,'counterpart_ledger_account_id'=>$customerLedgerId,
   'currency_id'=>$note->invoice->currency_id,'amount'=>(string)$value,
   'reference'=>$approvalReference,'description'=>'Customer refund '.$note->number,
   'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
  ]);
  $record=SalesCreditNoteRefund::create([
   'sales_credit_note_id'=>$note->id,'money_transaction_id'=>$payment->id,
   'source_account_id'=>$account->id,'amount'=>(string)$value,
   'authorization_reference'=>$approvalReference,'created_by'=>$actorId,
  ]);
  ActivityLog::record('sales.refund.posted','sales_credit_note',$note->id,
   'Credit note refund posted to cash/bank and customer receivable.',
   ['refund_id'=>$record->id,'money_transaction_id'=>$payment->id,'journal_id'=>$journal->id]);
  return $record;
 }
}