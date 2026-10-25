<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code','name','symbol','decimal_places','is_active','is_base'];
    protected $casts = ['is_active'=>'boolean','is_base'=>'boolean','decimal_places'=>'integer'];
}
