<?php
namespace App\Services\Accounting;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class JournalPostingService {
 public function post(JournalEntry $journal,int $userId):JournalEntry {
  return DB::transaction(function()use($journal,$userId){
   // Serialize postings affecting mapped party ledgers with reconciliation approvals.
   $currencyId=JournalEntry::whereKey($journal->id)->value('currency_id');
   $accountIds=DB::table('journal_lines')->where('journal_entry_id',$journal->id)
    ->pluck('ledger_account_id')->unique()->values()->all();
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
   if ((int)$entry->currency_id !== (int)$currencyId) throw ValidationException::withMessages(['currency_id'=>'Journal currency changed during posting. Please retry.']);
   if($entry->status!=='draft')throw ValidationException::withMessages(['status'=>'Only draft journals may be posted.']);
   $lines=$entry->lines()->get();if($lines->count()<2)throw ValidationException::withMessages(['lines'=>'A journal needs at least two lines.']);
   $debits=0;$credits=0;
   foreach($lines as $line){$account=LedgerAccount::findOrFail($line->ledger_account_id);if($account->is_group||!$account->is_active)throw ValidationException::withMessages(['lines'=>'Posting requires active, non-group accounts.']);
    $d=(int)round((float)$line->debit*10000);$c=(int)round((float)$line->credit*10000);if(($d>0&&$c>0)||($d===0&&$c===0)||$d<0||$c<0)throw ValidationException::withMessages(['lines'=>'Each line must contain exactly one positive debit or credit.']);$debits+=$d;$credits+=$c;
   }
   if($debits!==$credits)throw ValidationException::withMessages(['lines'=>'Total debits must equal total credits.']);
   $entry->update(['status'=>'posted','posted_by'=>$userId,'posted_at'=>now()]);ActivityLog::record('journal.posted','journal_entry',$entry->id,'Journal '.$entry->number.' posted');return $entry;
  });
 }
 public function reverse(JournalEntry $journal,int $userId):JournalEntry {
  return DB::transaction(function()use($journal,$userId){$original=JournalEntry::whereKey($journal->id)->lockForUpdate()->firstOrFail();if(DB::table('trade_opening_balances')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Opening balances require a controlled adjustment workflow and cannot be reversed individually.']);if(DB::table('trade_documents')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Posted trade documents require a document-level reversal workflow.']);if(DB::table('currency_exchanges')->where('source_journal_id',$original->id)->orWhere('destination_journal_id',$original->id)->exists()||DB::table('money_transactions')->where('journal_entry_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'System-generated financial transaction journals cannot be reversed individually. Use the corresponding transaction workflow.']);if($original->status!=='posted'||JournalEntry::where('reversal_of_id',$original->id)->exists())throw ValidationException::withMessages(['status'=>'Only an unreversed posted journal can be reversed.']);
   $reverse=JournalEntry::create(['number'=>'REV-'.$original->number,'entry_date'=>now()->toDateString(),'reference'=>$original->number,'description'=>'Reversal: '.$original->description,'status'=>'draft','currency_id'=>$original->currency_id,'created_by'=>$userId,'reversal_of_id'=>$original->id]);
   foreach($original->lines as $line)$reverse->lines()->create(['ledger_account_id'=>$line->ledger_account_id,'debit'=>$line->credit,'credit'=>$line->debit,'memo'=>$line->memo]);
   $this->post($reverse,$userId);ActivityLog::record('journal.reversed','journal_entry',$original->id,'Journal '.$original->number.' reversed by '.$reverse->number);return $reverse->fresh();
  });
 }
}
