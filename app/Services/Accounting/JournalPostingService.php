<?php
namespace App\Services\Accounting;
use Brick\Math\BigDecimal;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class JournalPostingService {
 public function post(JournalEntry $journal,int $userId):JournalEntry {
  return DB::transaction(function()use($journal,$userId){
   // Shared gate serializes journal posting and month-close transitions.
   $closeService=app(\App\Services\FinancePeriodClosingService::class);
   $closeService->holdPostingGate();
   $postingDate=JournalEntry::whereKey($journal->id)->value('entry_date');
   if(!$postingDate)throw ValidationException::withMessages(['entry_date'=>'Journal not found.']);
   $closeService->assertOpenDate(substr((string)$postingDate,0,10));
   // Serialize postings affecting mapped party ledgers with reconciliation approvals.
   $currencyId=JournalEntry::whereKey($journal->id)->value('currency_id');
   $accountIds=DB::table('journal_lines')->where('journal_entry_id',$journal->id)
    ->pluck('ledger_account_id')->unique()->values()->all();
   // Every entity-specific ledger is tied to one currency. A journal using
   // that ledger in any other currency would corrupt party and cash balances.
   $allMappedAccounts=DB::table('entity_ledger_accounts')
    ->whereIn('ledger_account_id',$accountIds)->get();
   foreach($allMappedAccounts as $mapping){
    if((int)$mapping->currency_id!==(int)$currencyId){
     throw ValidationException::withMessages([
      'currency_id'=>'A mapped account belongs to a different currency. Choose matching accounts.',
     ]);
    }
   }
   $mapped=collect();
   if ($accountIds) {
    $mapped=DB::table('entity_ledger_accounts')->where('currency_id',$currencyId)
     ->whereIn('ledger_account_id',$accountIds)
     ->whereIn('purpose',['receivable','payable'])
     ->whereIn('entity_type',['customer','supplier'])->get();
    foreach ($mapped->unique(fn ($item) => $item->purpose.':'.$item->entity_id.':'.$item->currency_id)->sortBy(fn ($item) => $item->purpose.':'.str_pad((string)$item->entity_id, 20, '0', STR_PAD_LEFT)) as $mapping) {
     DB::table('reconciliation_cases')->where('type',$mapping->purpose)
      ->where('trade_party_id',$mapping->entity_id)
      ->where('currency_id',$currencyId)->lockForUpdate()->first();
    }
   }
   $entry=JournalEntry::whereKey($journal->id)->lockForUpdate()->firstOrFail();
   $currentAllMappedAccounts=DB::table('entity_ledger_accounts')
    ->whereIn('ledger_account_id',$accountIds)->get();
   $allKey=fn($item)=>$item->entity_type.':'.$item->entity_id.':'.$item->purpose.':'.$item->currency_id.':'.$item->ledger_account_id;
   if($currentAllMappedAccounts->map($allKey)->sort()->values()->all() !==
      $allMappedAccounts->map($allKey)->sort()->values()->all()){
    throw ValidationException::withMessages(['lines'=>'Entity ledger mappings changed during posting. Please retry.']);
   }
   $currentMappings=DB::table('entity_ledger_accounts')->where('currency_id',$currencyId)
    ->whereIn('ledger_account_id',$accountIds)->whereIn('purpose',['receivable','payable'])
    ->whereIn('entity_type',['customer','supplier'])->get();
   $mappingKey=fn ($item) => $item->entity_type.':'.$item->purpose.':'.$item->entity_id.':'.$item->currency_id.':'.$item->ledger_account_id;
   if ($currentMappings->map($mappingKey)->sort()->values()->all() !== $mapped->map($mappingKey)->sort()->values()->all()) throw ValidationException::withMessages(['lines'=>'Party account mappings changed during posting. Please retry.']);
   if ((int)$entry->currency_id !== (int)$currencyId) throw ValidationException::withMessages(['currency_id'=>'Journal currency changed during posting. Please retry.']);
   $lockedAccountIds=DB::table('journal_lines')->where('journal_entry_id',$entry->id)->pluck('ledger_account_id')->unique()->values()->all();
   if (array_diff($lockedAccountIds,$accountIds) || array_diff($accountIds,$lockedAccountIds)) throw ValidationException::withMessages(['lines'=>'Journal accounts changed during posting. Please retry.']);
   if($entry->status!=='draft')throw ValidationException::withMessages(['status'=>'Only draft journals may be posted.']);
   $lines=$entry->lines()->get();if($lines->count()<2)throw ValidationException::withMessages(['lines'=>'A journal needs at least two lines.']);
   $debits=BigDecimal::zero();$credits=BigDecimal::zero();
   foreach($lines as $line){$account=LedgerAccount::findOrFail($line->ledger_account_id);if($account->is_group||!$account->is_active)throw ValidationException::withMessages(['lines'=>'Posting requires active, non-group accounts.']);
    $d=BigDecimal::of((string)$line->debit);$c=BigDecimal::of((string)$line->credit);
    $dSign=$d->compareTo(BigDecimal::zero());$cSign=$c->compareTo(BigDecimal::zero());
    if(($dSign>0&&$cSign>0)||($dSign===0&&$cSign===0)||$dSign<0||$cSign<0)throw ValidationException::withMessages(['lines'=>'Each line must contain exactly one positive debit or credit.']);
    $debits=$debits->plus($d);$credits=$credits->plus($c);
   }
   if($debits->compareTo($credits)!==0)throw ValidationException::withMessages(['lines'=>'Total debits must equal total credits.']);
   $entry->update(['status'=>'posted','posted_by'=>$userId,'posted_at'=>now()]);ActivityLog::record('journal.posted','journal_entry',$entry->id,'Journal '.$entry->number.' posted');return $entry;
  });
 }
 public function reverse(JournalEntry $journal,int $userId):JournalEntry {
  return DB::transaction(function()use($journal,$userId){$original=JournalEntry::whereKey($journal->id)->lockForUpdate()->firstOrFail();if(DB::table('production_overhead_allocations')->join('journal_lines as jl','jl.id','=','production_overhead_allocations.source_journal_line_id')
    ->where('jl.journal_entry_id',$original->id)->whereIn('production_overhead_allocations.status',['submitted','approved'])->exists())
    throw ValidationException::withMessages(['status'=>'Finance must withdraw production cost allocations before reversing their source expense journal.']);
   if(DB::table('supplier_advance_applications')->where('journal_entry_id',$original->id)->exists()
   || DB::table('customer_advance_applications')->where('journal_entry_id',$original->id)->exists())
    throw ValidationException::withMessages(['status'=>'Applied customer or supplier advances require a dedicated document-level correction workflow.']);
   if(DB::table('shareholder_profit_distributions')->where('journal_entry_id',$original->id)->exists())
    throw ValidationException::withMessages(['status'=>'Posted shareholder profit distributions require a controlled resolution-linked correction.']);
   if(DB::table('fixed_asset_depreciation_entries')->where('journal_entry_id',$original->id)->exists()
   ||DB::table('fixed_asset_disposal_postings')->where('disposal_journal_id',$original->id)
     ->orWhere('acquisition_journal_id',$original->id)->exists()
   ||DB::table('supplier_debit_notes')->where('journal_entry_id',$original->id)->exists())
    throw ValidationException::withMessages(['status'=>'System-generated asset or supplier credit journals cannot be reversed independently.']);
   if(DB::table('trade_opening_balances')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Opening balances require a controlled adjustment workflow and cannot be reversed individually.']);if(DB::table('trade_documents')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Posted trade documents require a document-level reversal workflow.']);if(DB::table('payroll_periods')->where('approval_journal_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Approved payroll accruals require a payroll reversal workflow.']);if(DB::table('currency_exchanges')->where('source_journal_id',$original->id)->orWhere('destination_journal_id',$original->id)->exists()||DB::table('money_transactions')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'System-generated financial transaction journals cannot be reversed individually. Use the corresponding transaction workflow.']);if($original->reversal_of_id || $original->status!=='posted'||JournalEntry::where('reversal_of_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Only an unreversed posted journal can be reversed.']);
   $reverse=JournalEntry::create(['number'=>'REV-'.$original->number,'entry_date'=>now()->toDateString(),'reference'=>$original->number,'description'=>'Reversal: '.$original->description,'status'=>'draft','currency_id'=>$original->currency_id,'created_by'=>$userId,'reversal_of_id'=>$original->id]);
   foreach($original->lines as $line)$reverse->lines()->create(['ledger_account_id'=>$line->ledger_account_id,'debit'=>$line->credit,'credit'=>$line->debit,'memo'=>$line->memo]);
   $this->post($reverse,$userId);ActivityLog::record('journal.reversed','journal_entry',$original->id,'Journal '.$original->number.' reversed by '.$reverse->number);return $reverse->fresh();
  });
 }
}
