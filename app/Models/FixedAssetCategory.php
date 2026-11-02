<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetCategory extends Model
{
    protected $fillable = ['code', 'name', 'created_by'];
    public function assets() { return $this->hasMany(FixedAsset::class); }
}
