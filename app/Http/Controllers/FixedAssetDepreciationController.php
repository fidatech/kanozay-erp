<?php

namespace App\Http\Controllers;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationPolicy;
use App\Models\LedgerAccount;
use App\Services\FixedAssetDepreciationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;

class FixedAssetDepreciationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['maintenance.view','finance.view','administration.view']),403);
        $assets=FixedAsset::with('currency')->orderBy('asset_tag')->get();
        $accumulated=DB::table('fixed_asset_depreciation_entries')
            ->whereIn('fixed_asset_id',$assets->pluck('id'))
            ->select('fixed_asset_id')->selectRaw('SUM(amount) as total')
            ->groupBy('fixed_asset_id')->pluck('total','fixed_asset_id');
        $capitalizations=DB::table('fixed_asset_capitalizations as verified')
            ->join('journal_entries as origin','origin.id','=','verified.acquisition_journal_id')
            ->leftJoin('journal_entries as reversal','reversal.reversal_of_id','=','origin.id')
            ->whereIn('verified.fixed_asset_id',$assets->pluck('id'))
            ->select('verified.fixed_asset_id','origin.status','origin.reversal_of_id')
            ->selectRaw('COUNT(reversal.id) as reversals')
            ->groupBy('verified.fixed_asset_id','origin.status','origin.reversal_of_id')
            ->get()->keyBy('fixed_asset_id');
        $bookValues=[];
        foreach($assets as $asset){
            $remaining=BigDecimal::of((string)$asset->acquisition_cost)
                ->minus((string)($accumulated[$asset->id]??'0'));
            $bookValues[$asset->id]=[
                'accumulated'=>(string)BigDecimal::of((string)($accumulated[$asset->id]??'0'))->toScale(4),
                'book_value'=>$remaining->isNegative()?null:(string)$remaining->toScale(4),
                'overdepreciated'=>$remaining->isNegative(),
                'capitalized'=>$capitalizations->has($asset->id),
                'source_current'=>($capitalizations->get($asset->id)?->status==='posted' &&
                    !$capitalizations->get($asset->id)?->reversal_of_id &&
                    (int)($capitalizations->get($asset->id)?->reversals??0)===0),
            ];
        }
        return view('assets.depreciation',[
            'assets'=>$assets,
            'bookValues'=>$bookValues,
            'policies'=>FixedAssetDepreciationPolicy::with(['expenseLedger','accumulatedLedger'])->get()->keyBy('fixed_asset_id'),
            'entries'=>FixedAssetDepreciationEntry::with(['asset','journal'])->latest('period_month')->latest('id')->paginate(25),
            'expenseAccounts'=>LedgerAccount::where('type','expense')->where('is_group',false)->where('is_active',true)->orderBy('code')->get(),
            'assetAccounts'=>LedgerAccount::where('type','asset')->where('is_group',false)->where('is_active',true)->orderBy('code')->get(),
        ]);
    }
    public function savePolicy(Request $request,FixedAsset $asset,FixedAssetDepreciationService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $request->merge(['salvage_value'=>\App\Support\LocalizedDecimal::normalize($request->input('salvage_value'))]);
        $data=$request->validate([
            'useful_life_months'=>['required','integer','between:1,1200'],
            'salvage_value'=>['required','regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/'],
            'start_month'=>['required','date_format:Y-m'],
            'expense_ledger_id'=>['required','integer',Rule::exists('ledger_accounts','id')->where('type','expense')->where('is_group',false)->where('is_active',true)],
            'accumulated_ledger_id'=>['required','integer',Rule::exists('ledger_accounts','id')->where('type','asset')->where('is_group',false)->where('is_active',true)],
        ]);
        $service->policy($asset,$data,$request->user()->id);
        return redirect()->route('workspace.page',['assets','depreciation'])->with('success','Straight-line depreciation policy approved.');
    }
    public function post(Request $request,FixedAsset $asset,FixedAssetDepreciationService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $data=$request->validate(['month'=>['required','date_format:Y-m']]);
        $service->post($asset,$data['month'],$request->user()->id);
        return redirect()->route('workspace.page',['assets','depreciation'])->with('success','Depreciation posted with a balanced journal entry.');
    }
}
