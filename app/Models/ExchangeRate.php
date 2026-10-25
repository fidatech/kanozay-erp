<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = ['from_currency_id','to_currency_id','rate','effective_date','source','notes','created_by'];
    protected $casts = ['effective_date'=>'date'];
    public function fromCurrency(): BelongsTo { return $this->belongsTo(Currency::class, 'from_currency_id'); }
    public function toCurrency(): BelongsTo { return $this->belongsTo(Currency::class, 'to_currency_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
