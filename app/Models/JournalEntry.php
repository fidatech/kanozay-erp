<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class JournalEntry extends Model {
 protected $fillable=['number','entry_date','reference','description','status','currency_id','created_by','posted_by','posted_at','reversal_of_id'];
 protected $casts=['entry_date'=>'date','posted_at'=>'datetime'];
 public function currency():\Illuminate\Database\Eloquent\Relations\BelongsTo{return $this->belongsTo(Currency::class);}
 public function lines():HasMany{return $this->hasMany(JournalLine::class);}
}
