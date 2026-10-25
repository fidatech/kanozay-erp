<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TradeDocument extends Model {
 protected $fillable=['type','number','trade_party_id','currency_id','document_date','due_date','amount','reference','description','journal_entry_id','created_by'];
 public function party(){return $this->belongsTo(TradeParty::class,'trade_party_id');}
 public function currency(){return $this->belongsTo(Currency::class);}
 public function allocations(){return $this->hasMany(TradeSettlementAllocation::class,'trade_document_id');}
 public function journal(){return $this->belongsTo(JournalEntry::class,'journal_entry_id');}
}
