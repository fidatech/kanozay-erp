<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CurrencyExchange extends Model {
 protected $fillable=['exchange_date','source_account_id','destination_account_id','source_currency_id','destination_currency_id','source_amount','destination_amount','applied_rate','reference_rate_id','reference','description','source_journal_id','destination_journal_id','created_by'];
 public function source(){return $this->belongsTo(FinancialAccount::class,'source_account_id');}
 public function destination(){return $this->belongsTo(FinancialAccount::class,'destination_account_id');}
 public function sourceCurrency(){return $this->belongsTo(Currency::class,'source_currency_id');}
 public function destinationCurrency(){return $this->belongsTo(Currency::class,'destination_currency_id');}
 public function sourceJournal(){return $this->belongsTo(JournalEntry::class,'source_journal_id');}
 public function destinationJournal(){return $this->belongsTo(JournalEntry::class,'destination_journal_id');}
}
