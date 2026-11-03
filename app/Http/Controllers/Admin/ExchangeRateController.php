<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function index(Request $request): View {
        abort_unless($request->user()->can('administration.view'),403);
        $currencies=Currency::where('is_active',true)->orderBy('code')->get();
        $latest=ExchangeRate::with(['fromCurrency','toCurrency'])->orderByDesc('effective_date')->orderByDesc('id')->get()->unique(fn ($rate) => $rate->from_currency_id.':'.$rate->to_currency_id)->keyBy(fn ($rate) => $rate->from_currency_id.':'.$rate->to_currency_id);
        return view('admin.exchange-rates.index',['currencies'=>$currencies,'latestRates'=>$latest,'rates'=>ExchangeRate::with(['fromCurrency','toCurrency','creator'])->latest('effective_date')->latest('id')->paginate(25)]);
    }
    public function store(Request $request): RedirectResponse|JsonResponse {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['from_currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],'to_currency_id'=>['required','different:from_currency_id',Rule::exists('currencies','id')->where('is_active',true)],'rate'=>['required','numeric','gt:0','decimal:0,12','lt:1000000000000'],'effective_date'=>['required','date_format:Y-m-d'],'source'=>['nullable','string','max:150'],'notes'=>['nullable','string','max:1000']]);
        $rate = DB::transaction(function() use($data,$request) {
            $rate=ExchangeRate::create($data+['created_by'=>$request->user()->id]);
            ActivityLog::record('exchange_rate.recorded','exchange_rate',$rate->id,'Exchange rate recorded',[
                'from'=>$rate->from_currency_id,'to'=>$rate->to_currency_id,'date'=>$rate->effective_date->toDateString(),
            ]);
            return $rate;
        });
        if ($request->expectsJson()) {
            $current=ExchangeRate::where('from_currency_id',$rate->from_currency_id)
                ->where('to_currency_id',$rate->to_currency_id)
                ->orderByDesc('effective_date')->orderByDesc('id')->firstOrFail();
            return response()->json([
                'message'=>'Exchange rate recorded successfully.',
                'current'=>[
                    'rate'=>(string)BigDecimal::of((string)$current->rate)->toScale(12), 'effective_date'=>$current->effective_date->toDateString(),
                    'source'=>$current->source ?: 'Manual',
                ],
                'rate'=>[
                    'id'=>$rate->id,'from_currency_id'=>$rate->from_currency_id,'to_currency_id'=>$rate->to_currency_id,
                    'rate'=>(string)BigDecimal::of((string)$rate->rate)->toScale(12),'effective_date'=>$rate->effective_date->toDateString(),
                    'source'=>$rate->source ?: 'Manual','notes'=>$rate->notes,
                    'recorded_by'=>$request->user()->name,
                    'recorded_at'=>$rate->created_at->timezone('Asia/Kabul')->format('d M Y H:i'),
                ],
            ],201);
        }
        return back()->with('status','Exchange rate recorded successfully.');
    }
    public function convert(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->can('administration.view'),403);
        $data=$request->validate([
            'from_currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
            'to_currency_id'=>['required','different:from_currency_id',Rule::exists('currencies','id')->where('is_active',true)],
            'amount'=>['required','numeric','gt:0','decimal:0,6','lt:1000000000000'],
            'as_of'=>['required','date_format:Y-m-d'],
        ]);
        $rate=ExchangeRate::where('from_currency_id',$data['from_currency_id'])
            ->where('to_currency_id',$data['to_currency_id'])
            ->whereDate('effective_date','<=',$data['as_of'])
            ->orderByDesc('effective_date')->orderByDesc('id')->first();
        if (!$rate) return response()->json(['message'=>'No historical rate available for this direction and date.'],404);
        $currency=Currency::findOrFail($data['to_currency_id']);
        $converted=BigDecimal::of((string)$data['amount'])->multipliedBy((string)$rate->rate)
            ->toScale($currency->decimal_places,RoundingMode::HalfUp);
        return response()->json(['converted_amount'=>(string)$converted,'rate'=>(string)BigDecimal::of((string)$rate->rate)->toScale(12),
            'effective_date'=>$rate->effective_date->toDateString(),'source'=>$rate->source,'target_code'=>$currency->code]);
    }

}
