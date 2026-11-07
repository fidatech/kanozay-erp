<?php

namespace App\Http\Controllers;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationPolicy;
use App\Models\LedgerAccount;
use App\Services\FixedAssetDepreciationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FixedAssetDepreciationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['maintenance.view','finance.view','administration.view']),403);
        return view('assets.depreciation',[
            'assets'=>FixedAsset::with('currency')->orderBy('asset_tag')->get(),
            'policies'=>FixedAssetDepreciationPolicy::with(['expenseLedger','accumulatedLedger'])->get()->keyBy('fixed_asset_id'),
            'entries'=>FixedAssetDepreciationEntry::with(['asset','journal'])->latest('period_month')->latest('id')->paginate(25),
            'expenseAccounts'=>LedgerAccount::where('type','expense')->where('is_group',false)->where('is_active',true)->orderBy('code')->get(),
            'assetAccounts'=>LedgerAccount::where('type','asset')->where('is_group',false)->where('is_active',true)->orderBy('code')->get(),
        ]);
    }
    public function savePolicy(Request $request,FixedAsset $asset,FixedAssetDepreciationService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
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
