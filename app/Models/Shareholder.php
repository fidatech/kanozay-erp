<?php

namespace App\Models;

use App\Models\Concerns\ProvisionsLedgerAccounts;
use Illuminate\Database\Eloquent\Model;

class Shareholder extends Model
{
    use ProvisionsLedgerAccounts;

    protected $fillable = ['name', 'code', 'phone', 'email', 'address', 'notes', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function currencies()
    {
        return $this->belongsToMany(Currency::class, 'shareholder_currencies')->withTimestamps();
    }

    public function transactions()
    {
        return $this->hasMany(ShareholderTransaction::class);
    }

    protected function ledgerEntityType(): string
    {
        return 'shareholder';
    }

    protected function ledgerEntityName(): string
    {
        return $this->name;
    }
}
