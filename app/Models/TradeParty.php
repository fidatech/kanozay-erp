<?php
namespace App\Models;
use App\Models\Concerns\ProvisionsLedgerAccounts;
use Illuminate\Database\Eloquent\Model;
class TradeParty extends Model {
 use ProvisionsLedgerAccounts;
 protected $fillable=['type','name','code','contact_person','phone','email','address','notes','is_active','created_by'];
 protected $casts=['is_active'=>'boolean'];
 public function currencies(){return $this->belongsToMany(Currency::class,'trade_party_currencies')->withTimestamps();}
 protected function ledgerEntityType():string{return $this->type;}
 protected function ledgerEntityName():string{return $this->name;}
}
