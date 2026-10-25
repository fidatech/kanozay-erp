<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(Request $request): View {
        abort_unless($request->user()->can('administration.view'),403);
        return view('admin.currencies.index',['currencies'=>Currency::orderByDesc('is_base')->orderBy('code')->get()]);
    }
    public function store(Request $request): RedirectResponse {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['code'=>['required','regex:/^[A-Za-z]{3}$/',Rule::unique('currencies','code')], 'name'=>['required','string','max:100'], 'symbol'=>['nullable','string','max:12'], 'decimal_places'=>['required','integer','between:0,6']]);
        $data['code']=strtoupper($data['code']);
        abort_if(Currency::where('code',$data['code'])->exists(),422,'Currency code already exists.');
        DB::transaction(function() use($data) { $currency=Currency::create($data+['is_base'=>!Currency::where('is_base',true)->exists()]); ActivityLog::record('currency.created','currency',$currency->id,'Currency '.$currency->code.' created'); });
        return back()->with('status','Currency created successfully.');
    }
    public function update(Request $request, Currency $currency): RedirectResponse {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['name'=>['required','string','max:100'],'symbol'=>['nullable','string','max:12'],'decimal_places'=>['required','integer','between:0,6'],'is_active'=>['required','boolean']]);
        if($currency->is_base && !$data['is_active']) return back()->withErrors(['currency'=>'The base currency cannot be disabled.']);
        DB::transaction(function() use($currency,$data) { $currency->update($data); ActivityLog::record('currency.updated','currency',$currency->id,'Currency '.$currency->code.' updated'); });
        return back()->with('status','Currency updated successfully.');
    }
    public function base(Request $request, Currency $currency): RedirectResponse {
        abort_unless($request->user()->can('administration.update'),403);
        if(!$currency->is_active) return back()->withErrors(['currency'=>'Activate the currency before selecting it as base.']);
        if(\App\Models\ExchangeRate::exists()) return back()->withErrors(['currency'=>'Base currency is locked after exchange rates have been recorded.']);
        DB::transaction(function() use($currency) { Currency::where('is_base',true)->update(['is_base'=>false]); $currency->update(['is_base'=>true]); ActivityLog::record('currency.base_changed','currency',$currency->id,'Base currency changed to '.$currency->code); });
        return back()->with('status','Base currency updated.');
    }
}
