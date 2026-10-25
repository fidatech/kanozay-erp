<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function index(Request $request): View {
        abort_unless($request->user()->can('administration.view'),403);
        return view('admin.exchange-rates.index',['currencies'=>Currency::where('is_active',true)->orderBy('code')->get(), 'rates'=>ExchangeRate::with(['fromCurrency','toCurrency','creator'])->latest('effective_date')->latest('id')->paginate(25)]);
    }
    public function store(Request $request): RedirectResponse {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['from_currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'to_currency_id'=>['required','different:from_currency_id',Rule::exists('currencies','id')->where('is_active',true)],'rate'=>['required','numeric','gt:0','decimal:0,12','lt:1000000000000'],'effective_date'=>['required','date'],'source'=>['nullable','string','max:150'],'notes'=>['nullable','string','max:1000']]);
        DB::transaction(function() use($data,$request) { $rate=ExchangeRate::create($data+['created_by'=>$request->user()->id]); ActivityLog::record('exchange_rate.recorded','exchange_rate',$rate->id,'Exchange rate recorded',['from'=>$rate->from_currency_id,'to'=>$rate->to_currency_id,'date'=>$rate->effective_date->toDateString()]); });
        return back()->with('status','Exchange rate recorded successfully.');
    }
}
