<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JournalLine extends Model {
 public function account():\Illuminate\Database\Eloquent\Relations\BelongsTo{return $this->belongsTo(LedgerAccount::class,'ledger_account_id');}
 protected $fillable=['journal_entry_id','ledger_account_id','debit','credit','memo'];
}
