<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PurchaseReturnRecord;
use App\Models\SupplierDebitNote;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class SupplierDebitNoteService {
 public function __construct(private readonly JournalPostingService $posting){}

 public function post(PurchaseReturnRecord $return,array $data,int $actor):SupplierDebitNote {
  return DB::transaction(function()use($return,$data,$actor){
   $existing=SupplierDebitNote::where('submission_token',$data['submission_token'])->first();
   if($existing){
    if($existing->purchase_return_record_id===$return->id &&
       $existing->trade_document_id===(int)$data['trade_document_id'] &&
       BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']))
     return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Token already belongs to another supplier debit note.']);
   }
   $locked=PurchaseReturnRecord::whereKey($return->id)->lockForUpdate()->firstOrFail();
   if($locked->status!=='posted_pending_credit' || SupplierDebitNote::where('purchase_return_record_id',$locked->id)->exists())
    throw ValidationException::withMessages(['purchase_return_record_id'=>'A supplier credit has already been processed for this return.']);
   if($data['credit_date']<$locked->returned_on->toDateString())
    throw ValidationException::withMessages(['credit_date'=>'Credit date cannot precede the physical return.']);
   $party=TradeParty::whereKey($locked->order->supplier_id)->lockForUpdate()->firstOrFail();
   $document=TradeDocument::whereKey($data['trade_document_id'])->lockForUpdate()->firstOrFail();
   if($document->type!=='payable' || $document->trade_party_id!==$party->id || $document->currency_id!==$locked->currency_id)
    throw ValidationException::withMessages(['trade_document_id'=>'Choose an existing payable for this supplier and receipt currency.']);

   $sourceTotal=BigDecimal::zero();
   $lines=$locked->lines;
   if($lines->isEmpty())throw ValidationException::withMessages(['amount'=>'Return has no recorded material lines.']);
   foreach($lines as $line){
    if($line->supplier_credit_value_snapshot===null)
     throw ValidationException::withMessages(['amount'=>'Original purchase unit price is missing. Reconcile before posting credit.']);
    $sourceTotal=$sourceTotal->plus((string)$line->supplier_credit_value_snapshot);
   }
   $max=$sourceTotal->toScale(4,RoundingMode::HalfUp);
   $amount=BigDecimal::of((string)$data['amount'])->toScale(4,RoundingMode::Unnecessary);
   if(!$amount->isPositive() || $amount->isGreaterThan($max))
    throw ValidationException::withMessages(['amount'=>'Approved supplier credit cannot exceed the original received unit-price value '.$max.'.']);
   $remaining=BigDecimal::of((string)$document->amount);
   foreach($document->allocations as $allocation)$remaining=$remaining->minus((string)$allocation->amount);
   foreach($document->credits as $credit)$remaining=$remaining->minus((string)$credit->amount);
   if($amount->isGreaterThan($remaining))
    throw ValidationException::withMessages(['amount'=>'Supplier credit exceeds the unpaid balance of the selected payable.']);

   $payable=DB::table('entity_ledger_accounts')->where([
    'entity_type'=>'supplier','entity_id'=>$party->id,'currency_id'=>$locked->currency_id,
    'purpose'=>'payable',
   ])->value('ledger_account_id');
   $offset=LedgerAccount::whereKey($data['credit_offset_ledger_id'])->where('is_active',true)
     ->where('is_group',false)->first();
   if(!$payable || !$offset || !in_array($offset->type,['asset','expense'],true) ||
       $offset->id===(int)$payable ||
       DB::table('entity_ledger_accounts')->where('ledger_account_id',$offset->id)->exists())
    throw ValidationException::withMessages(['credit_offset_ledger_id'=>'Choose an approved, unmapped inventory asset or expense account for the credit reversal.']);
   $note=SupplierDebitNote::create([
    'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
    'purchase_return_record_id'=>$locked->id,'trade_document_id'=>$document->id,
    'credit_offset_ledger_id'=>$offset->id,'currency_id'=>$locked->currency_id,
    'credit_date'=>$data['credit_date'],'amount'=>(string)$amount,
    'supplier_credit_reference'=>$data['supplier_credit_reference'],
    'review_notes'=>$data['review_notes'],'created_by'=>$actor,
   ]);
   $number='SDN-'.str_pad((string)$note->id,7,'0',STR_PAD_LEFT);
   $journal=JournalEntry::create([
    'number'=>$number,'entry_date'=>$data['credit_date'],'reference'=>$locked->number,
    'description'=>'Supplier debit note '.$number.' for '.$locked->number.
      ' / '.$data['supplier_credit_reference'],
    'currency_id'=>$locked->currency_id,'created_by'=>$actor,'status'=>'draft',
   ]);
   $journal->lines()->createMany([
    ['ledger_account_id'=>$payable,'debit'=>(string)$amount,'credit'=>'0','memo'=>'Reduce supplier payable'],
    ['ledger_account_id'=>$offset->id,'debit'=>'0','credit'=>(string)$amount,'memo'=>'Approved return/credit offset'],
   ]);
   $this->posting->post($journal,$actor);
   // Posted note is immutable; set its final number and journal with a guarded
   // DB update within this same transaction (before exposing it to readers).
   DB::table('supplier_debit_notes')->where('id',$note->id)
    ->update(['number'=>$number,'journal_entry_id'=>$journal->id,'updated_at'=>now()]);
   DB::table('trade_document_credits')->insert([
    'trade_document_id'=>$document->id,'sales_credit_note_id'=>null,
    'supplier_debit_note_id'=>$note->id,'credit_date'=>$data['credit_date'],
    'amount'=>(string)$amount,'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('purchase_return_records')->where('id',$locked->id)
    ->update(['financial_status'=>'supplier_credit_posted','updated_at'=>now()]);
   ActivityLog::record('supplier.debit_note.posted','supplier_debit_note',$note->id,
     'Supplier payable credited by finance against existing source-priced return.',
     ['journal'=>$journal->number,'amount'=>(string)$amount,'currency_id'=>$locked->currency_id]);
   return $note->fresh();
  });
 }
}
