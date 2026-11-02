<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = ['name','legal_name','registration_number','tax_number','email','phone','website','address','city','country','timezone','default_locale','date_format','fiscal_start_month','fiscal_start_day','updated_by'];
}
