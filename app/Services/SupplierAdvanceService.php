<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\PurchaseOrder;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierAdvanceService
{
 public function __construct(private readonly JournalPostingService $posting) {}

 public function pay(PurchaseOrder $order,array $data,int $actorId):object
 {
  return DB::transaction(function()use($order,$data,$actorId){
   $existing=DB::table('supplier_advance_postings')->where('submission_token',$data['submission_token'])->first();
   if($existing){
    if((int)$existing->purchase_order_id===$order->id &&
        BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']) &&
        (int)$existing->source_account_id===(int)$data['source_account_id'] &&
        $existing->paid_on===$data['paid_on'] &&
        $existing->reference===$data['reference'])
     return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Token is already used for another advance.']);
   }
   $po=PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
   if($po->status!=='confirmed')
    throw ValidationException::withMessages(['purchase_order_id'=>'Only confirmed purchase orders may receive posted cash advances.']);
   $supplier=TradeParty::whereKey($po->supplier_id)->lockForUpdate()->firstOrFail();
   if(!$supplier->is_active || $supplier->type!=='supplier' ||
       !$supplier->currencies()->whereKey($po->currency_id)->exists())
    throw ValidationException::withMessages(['supplier_id'=>'Supplier is inactive or purchase currency is unavailable.']);
   $cash=FinancialAccount::whereKey($data['source_account_id'])->lockForUpdate()->firstOrFail();
   if(!$cash->is_active || (int)$cash->currency_id!==(int)$po->currency_id)
    throw ValidationException::withMessages(['source_account_id'=>'Choose an active cash, bank or sarafi account in the purchase currency.']);
   $cashLedger=DB::table('entity_ledger_accounts')->where([
      'entity_type'=>$cash->type,'entity_id'=>$cash->id,
      'currency_id'=>$po->currency_id,'purpose'=>'cash',
   ])->value('ledger_account_id');
   // Supplier advances must be traceable in that supplier's own ledger,
   // not posted to an arbitrary shared asset account.
   $advanceLedgerId=DB::table('entity_ledger_accounts')->where([
      'entity_type'=>'supplier','entity_id'=>$supplier->id,
      'currency_id'=>$po->currency_id,'purpose'=>'advance',
   ])->value('ledger_account_id');
   $advance=$advanceLedgerId?LedgerAccount::whereKey($advanceLedgerId)
      ->where('type','asset')->where('is_active',true)->where('is_group',false)->first():null;
   if(!$cashLedger || !$advance || (int)$cashLedger===$advance->id)
    throw ValidationException::withMessages(['supplier_id'=>'Supplier-specific advance ledger must be provisioned in the purchase currency.']);
   $value=BigDecimal::of((string)$data['amount'])->toScale(4);
   if(!$value->isPositive() || $value->isGreaterThan('999999999999.9999'))
    throw ValidationException::withMessages(['amount'=>'Supplier advance must be a positive supported amount.']);
   DocumentSequence::firstOrCreate(['document_type'=>'payment'],
     ['prefix'=>'PAY-','next_number'=>1,'padding'=>6,'is_active'=>true]);
   $number=DocumentSequence::issue('payment');
   $journal=JournalEntry::create([
      'number'=>$number,'entry_date'=>$data['paid_on'],'reference'=>$data['reference'],
      'description'=>'Supplier advance '.$supplier->name.' / '.$po->number,
      'currency_id'=>$po->currency_id,'created_by'=>$actorId,'status'=>'draft',
   ]);
   $journal->lines()->createMany([
    ['ledger_account_id'=>$advance->id,'debit'=>(string)$value,'credit'=>'0','memo'=>'Supplier advance receivable'],
    ['ledger_account_id'=>$cashLedger,'debit'=>'0','credit'=>(string)$value,'memo'=>'Cash/bank advance paid'],
   ]);
   $this->posting->post($journal,$actorId);
   $money=MoneyTransaction::create([
      'submission_token'=>$data['submission_token'],'type'=>'payment',
      'transaction_date'=>$data['paid_on'],'source_account_id'=>$cash->id,
      'destination_account_id'=>null,'counterpart_ledger_account_id'=>$advance->id,
      'currency_id'=>$po->currency_id,'amount'=>(string)$value,
      'reference'=>$data['reference'],
      'description'=>'Supplier advance on '.$po->number.' for '.$supplier->name,
      'journal_entry_id'=>$journal->id,'created_by'=>$actorId,
   ]);
   $id=DB::table('supplier_advance_postings')->insertGetId([
    'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
    'purchase_order_id'=>$po->id,'supplier_id'=>$supplier->id,'currency_id'=>$po->currency_id,
    'source_account_id'=>$cash->id,'advance_ledger_id'=>$advance->id,
    'money_transaction_id'=>$money->id,'paid_on'=>$data['paid_on'],'amount'=>(string)$value,
    'reference'=>$data['reference'],'created_by'=>$actorId,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('supplier_advance_postings')->where('id',$id)
     ->update(['number'=>'SAP-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
   ActivityLog::record('supplier.advance.paid','supplier_advance_posting',$id,
      'Posted supplier advance for '.$po->number,['money_transaction_id'=>$money->id,'journal'=>$number]);
   return DB::table('supplier_advance_postings')->where('id',$id)->first();
  });
 }

 public function remaining(int $advanceId):BigDecimal
 {
  $advance=DB::table('supplier_advance_postings')->where('id',$advanceId)->first();
  if(!$advance)return BigDecimal::zero();
  $balance=BigDecimal::of((string)$advance->amount);
  foreach(DB::table('supplier_advance_applications')->where('supplier_advance_posting_id',$advanceId)->get() as $row)
   $balance=$balance->minus((string)$row->amount);
  return $balance;
 }

 public function apply(int $advanceId,array $data,int $actorId):object
 {
  return DB::transaction(function()use($advanceId,$data,$actorId){
   $existing=DB::table('supplier_advance_applications')->where('submission_token',$data['submission_token'])->first();
   if($existing){
    if((int)$existing->supplier_advance_posting_id===$advanceId &&
      (int)$existing->trade_document_id===(int)$data['trade_document_id'] &&
      BigDecimal::of((string)$existing->amount)->isEqualTo((string)$data['amount']) &&
      $existing->applied_on===$data['applied_on'] &&
      $existing->approval_reference===$data['approval_reference'])return $existing;
    throw ValidationException::withMessages(['submission_token'=>'Advance application token belongs to another settlement.']);
   }
   $advance=DB::table('supplier_advance_postings')->where('id',$advanceId)->lockForUpdate()->first();
   abort_unless($advance,404);
   $party=TradeParty::whereKey($advance->supplier_id)->lockForUpdate()->firstOrFail();
   $doc=TradeDocument::whereKey($data['trade_document_id'])->lockForUpdate()->firstOrFail();
   if($doc->type!=='payable' || (int)$doc->trade_party_id!==(int)$party->id ||
     (int)$doc->currency_id!==(int)$advance->currency_id)
    throw ValidationException::withMessages(['trade_document_id'=>'Select an open payable for the same supplier and currency.']);
   if($data['applied_on']<$advance->paid_on ||
      $data['applied_on']<(string)$doc->document_date)
    throw ValidationException::withMessages(['applied_on'=>'Application date cannot precede the advance payment or invoice date.']);
   $amount=BigDecimal::of((string)$data['amount'])->toScale(4);
   if(!$amount->isPositive() || $amount->isGreaterThan($this->remaining($advanceId)))
    throw ValidationException::withMessages(['amount'=>'Supplier advance has insufficient unapplied funds.']);
   $open=BigDecimal::of((string)$doc->amount);
   foreach($doc->allocations as $allocation)$open=$open->minus((string)$allocation->amount);
   foreach($doc->credits as $credit)$open=$open->minus((string)$credit->amount);
   if($amount->isGreaterThan($open))
    throw ValidationException::withMessages(['amount'=>'Advance application exceeds the unpaid supplier invoice amount.']);
   $payableLedger=DB::table('entity_ledger_accounts')->where([
       'entity_type'=>'supplier','entity_id'=>$party->id,'currency_id'=>$advance->currency_id,'purpose'=>'payable',
   ])->value('ledger_account_id');
   $advanceLedger=LedgerAccount::whereKey($advance->advance_ledger_id)
      ->where('type','asset')->where('is_active',true)->where('is_group',false)->first();
   if(!$payableLedger || !$advanceLedger || (int)$payableLedger===$advanceLedger->id)
    throw ValidationException::withMessages(['advance'=>'Supplier advance or payable ledger mapping needs reconciliation.']);
   $journal=JournalEntry::create([
    'number'=>'TMP-'.Str::uuid(),'entry_date'=>$data['applied_on'],
    'reference'=>$advance->number,'description'=>'Apply supplier advance '.$advance->number.' to '.$doc->number,
    'currency_id'=>$advance->currency_id,'created_by'=>$actorId,'status'=>'draft',
   ]);
   $journal->lines()->createMany([
    ['ledger_account_id'=>$payableLedger,'debit'=>(string)$amount,'credit'=>'0','memo'=>'Reduce supplier payable'],
    ['ledger_account_id'=>$advanceLedger->id,'debit'=>'0','credit'=>(string)$amount,'memo'=>'Clear supplier prepayment'],
   ]);
   $this->posting->post($journal,$actorId);
   $id=DB::table('supplier_advance_applications')->insertGetId([
    'submission_token'=>$data['submission_token'],'number'=>'TMP-'.Str::uuid(),
    'supplier_advance_posting_id'=>$advanceId,'trade_document_id'=>$doc->id,
    'journal_entry_id'=>$journal->id,'applied_on'=>$data['applied_on'],
    'amount'=>(string)$amount,'approval_reference'=>$data['approval_reference'],
    'created_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
   ]);
   $number='SAA-'.str_pad((string)$id,7,'0',STR_PAD_LEFT);
   DB::table('supplier_advance_applications')->where('id',$id)->update(['number'=>$number]);
   DB::table('journal_entries')->where('id',$journal->id)->update(['number'=>$number]);
   DB::table('trade_document_credits')->insert([
    'trade_document_id'=>$doc->id,'sales_credit_note_id'=>null,
    'supplier_debit_note_id'=>null,'supplier_advance_application_id'=>$id,
    'credit_date'=>$data['applied_on'],'amount'=>(string)$amount,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('supplier.advance.applied','supplier_advance_application',$id,
    'Supplier prepayment allocated to posted payable '.$doc->number,
    ['journal'=>$number,'advance_id'=>$advanceId,'amount'=>(string)$amount]);
   return DB::table('supplier_advance_applications')->where('id',$id)->first();
  });
 }
}
