<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Services\ManufacturingCostAccountingService;
use App\Services\ManufacturingLedgerPolicyService;
use App\Services\ProductionCostReadinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ManufacturingCostAccountingController extends Controller
{
    public function index(Request $request,ProductionCostReadinessService $readiness)
    {
        abort_unless($request->user()->canAny([
            'finance.view','finance.update','manufacturing.view','administration.view','reports.view',
        ]),403);
        $filter=$request->validate([
            'status'=>['nullable',Rule::in(['pending_qc','qc_approved','released','qc_rejected'])],
        ]);
        $batches=ProductionBatch::query()->with(['costs','finishedGood'])
            ->when($filter['status']??null,fn($q,$v)=>$q->where('status',$v))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $policies=DB::table('manufacturing_cost_ledger_policies as policy')
            ->join('currencies as currency','currency.id','=','policy.currency_id')
            ->select('policy.*','currency.code as currency_code')
            ->orderBy('currency.code')->get();
        $posted=DB::table('production_batch_cost_postings as posting')
            ->join('journal_entries as issue','issue.id','=','posting.issue_journal_id')
            ->join('journal_entries as capital',
                'capital.id','=','posting.capitalization_journal_id')
            ->whereIn('posting.production_batch_id',$batches->getCollection()->pluck('id'))
            ->select('posting.*','issue.number as issue_journal_number',
                'capital.number as capital_journal_number')
            ->get()->keyBy('production_batch_id');
        return view('production.cost-accounting',[
            'batches'=>$batches,'rows'=>$readiness->rows($batches->getCollection()),
            'posted'=>$posted,'policies'=>$policies,
            'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
            'assets'=>LedgerAccount::where('type','asset')
                ->where('is_active',true)->where('is_group',false)
                ->orderBy('code')->get(),
            'expenses'=>LedgerAccount::where('type','expense')
                ->where('is_active',true)->where('is_group',false)
                ->orderBy('code')->get(),
        ]);
    }

    public function policy(Request $request,ManufacturingLedgerPolicyService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $data=$request->validate([
            'currency_id'=>['required','integer',Rule::exists('currencies','id')->where('is_active',true)],
            'material_inventory_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'work_in_progress_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'finished_inventory_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'cogs_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
            'authorization_reference'=>['required','string','min:4','max:180'],
            'notes'=>['required','string','min:15','max:3000'],
        ]);
        $service->create($data,$request->user()->id);
        return redirect()->route('manufacturing.cost-accounting.index')
            ->with('success','Manufacturing accounting policy submitted. An independent authorized finance reviewer must approve it.');
    }

    public function approve(Request $request,int $policy,ManufacturingLedgerPolicyService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $data=$request->validate([
            'approval_reference'=>['required','string','min:4','max:180'],
        ]);
        $service->approve($policy,$data['approval_reference'],$request->user()->id);
        return back()->with('success','Original-currency manufacturing ledger policy independently approved.');
    }

    public function capitalize(Request $request,ProductionBatch $batch,
        ManufacturingCostAccountingService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $data=$request->validate([
            'accounting_date'=>['required','date','before_or_equal:today'],
            'approval_reference'=>['required','string','min:4','max:180'],
            'reconciliation_notes'=>['required','string','min:20','max:3000'],
            'confirm_source_ledgers'=>['accepted'],
        ]);
        $service->capitalize($batch->id,$data,$request->user()->id);
        return back()->with('success','Fully sourced material and approved overhead costs posted from WIP into finished inventory in the original currency. Unreconciled sources are never auto-capitalized.');
    }
}
