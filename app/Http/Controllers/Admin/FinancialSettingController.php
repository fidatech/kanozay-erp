<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\FinancialSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class FinancialSettingController extends Controller {
    public function edit(Request $request) { abort_unless($request->user()->can('administration.view'),403); return view('admin.financial.edit',['setting'=>FinancialSetting::first(),'currencies'=>Currency::where('is_active',true)->orderBy('code')->get()]); }
    public function update(Request $request) {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate([
            'base_currency_id'=>['nullable',Rule::exists('currencies','id')->where('is_active',true)],
            'fiscal_year_start'=>['required','date_format:m-d',function($attribute,$value,$fail){if(!preg_match('/^(\d{2})-(\d{2})$/',$value,$parts)||!checkdate((int)$parts[1],(int)$parts[2],2024))$fail('Select a valid fiscal month and day.');}],
            'allow_negative_stock'=>['required','boolean'],
            'require_payment_approval'=>['required','boolean'],
            'require_purchase_approval'=>['required','boolean'],
            'purchase_mode'=>['required',Rule::in(['local','international','both'])],
        ]);
        if(Currency::where('is_base',true)->exists() && (string)$data['base_currency_id'] !== (string)Currency::where('is_base',true)->value('id')) return back()->withErrors(['base_currency_id'=>'The financial base currency must match the currency selected on the Currencies page.']);
        DB::transaction(function() use($data,$request){$setting=FinancialSetting::first(); if($setting) $setting->update($data+['updated_by'=>$request->user()->id]); else $setting=FinancialSetting::create($data+['updated_by'=>$request->user()->id]); ActivityLog::record('financial_settings.updated','financial_settings',$setting->id,'Financial configuration updated');});
        return back()->with('status','Financial configuration saved.');
    }
}
