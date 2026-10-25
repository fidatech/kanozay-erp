<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MoneyTransaction extends Model {
 protected $fillable=['type','transaction_date','source_account_id','destination_account_id','counterpart_ledger_account_id','currency_id','amount','reference','description','journal_entry_id','created_by'];
 public function journal(){return $this->belongsTo(JournalEntry::class,'journal_entry_id');}
 public function currency(){return $this->belongsTo(Currency::class);}
 public function source(){return $this->belongsTo(FinancialAccount::class,'source_account_id');}
 public function destination(){return $this->belongsTo(FinancialAccount::class,'destination_account_id');}
}
