<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetAssignmentService;
use App\Services\FixedAssetCapitalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FixedAssetController extends Controller
{
    public function index(Request $request)
    {
        $this->viewAccess($request);
        return view('assets.index', [
            'assets' => FixedAsset::with(['category', 'currency', 'custodian'])->orderBy('asset_tag')->paginate(20),
            'categories' => FixedAssetCategory::orderBy('name')->get(),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, FixedAsset $asset)
    {
        $this->viewAccess($request);
        $capitalization=DB::table('fixed_asset_capitalizations as link')
            ->join('journal_entries as journal','journal.id','=','link.acquisition_journal_id')
            ->join('ledger_accounts as ledger','ledger.id','=','link.asset_ledger_id')
            ->where('link.fixed_asset_id',$asset->id)
            ->select('link.*','journal.number as journal_number',
                'ledger.code as asset_ledger_code')->first();
        $candidates=collect();
        if(!$capitalization && $request->user()->canAny(['finance.update','administration.update'])){
            $candidates=DB::table('journal_lines as line')
                ->join('journal_entries as journal','journal.id','=','line.journal_entry_id')
                ->join('ledger_accounts as ledger','ledger.id','=','line.ledger_account_id')
                ->leftJoin('journal_entries as reversal',
                    'reversal.reversal_of_id','=','journal.id')
                ->leftJoin('fixed_asset_capitalizations as existing',
                    'existing.journal_line_id','=','line.id')
                ->where('journal.status','posted')
                ->whereNull('journal.reversal_of_id')
                ->whereNull('reversal.id')->whereNull('existing.id')
                ->where('journal.reference',$asset->source_reference)
                ->where('journal.currency_id',$asset->currency_id)
                ->where('ledger.type','asset')->where('ledger.is_group',false)
                ->where('ledger.is_active',true)
                ->where('line.credit',0)
                ->where('line.debit',$asset->acquisition_cost)
                ->select('line.id as journal_line_id','journal.number as journal_number',
                    'journal.entry_date','ledger.code as ledger_code','line.debit')
                ->orderByDesc('journal.entry_date')->limit(50)->get();
        }
        return view('assets.show', [
            'capitalization'=>$capitalization,
            'capitalizationCandidates'=>$candidates,
            'asset' => $asset->load(['category', 'currency', 'custodian']),
            'assignments' => $asset->assignments()->with('employee')->orderByDesc('assigned_on')->get(),
            'employees' => EmployeeProfile::where('is_active', true)->orderBy('employee_number')->get(),
        ]);
    }

    public function category(Request $request)
    {
        $this->createAccess($request);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,31}$/', Rule::unique('fixed_asset_categories', 'code')],
            'name' => ['required', 'string', 'max:160'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $category = FixedAssetCategory::create($data + ['created_by' => $request->user()->id]);
            ActivityLog::record('fixed_asset.category.created', 'fixed_asset_category', $category->id, 'Asset category created');
        });
        return back()->with('success', 'Asset category created.');
    }

    public function store(Request $request)
    {
        $this->createAccess($request);
        $request->merge([
            'asset_tag' => strtoupper(trim((string) $request->input('asset_tag'))),
            'acquisition_cost' => $this->normalize($request->input('acquisition_cost')),
        ]);
        $data = $request->validate([
            'asset_tag' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,63}$/', Rule::unique('fixed_assets', 'asset_tag')],
            'name' => ['required', 'string', 'max:180'],
            'fixed_asset_category_id' => ['required', 'exists:fixed_asset_categories,id'],
            'acquired_on' => ['required', 'date', 'before_or_equal:today'],
            'acquisition_cost' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?$/', 'numeric', 'gt:0'],
            'currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_active', true)],
            'source_reference' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $asset = DB::transaction(function () use ($data, $request) {
            $record = FixedAsset::create($data + ['created_by' => $request->user()->id]);
            ActivityLog::record('fixed_asset.created', 'fixed_asset', $record->id, 'Asset '.$record->asset_tag.' registered');
            return $record;
        });
        return redirect()->route('assets.show', $asset)->with('success', 'Asset registered with source reference. No depreciation or accounting entry was posted.');
    }

    public function reconcileCapitalization(Request $request,FixedAsset $asset,FixedAssetCapitalizationService $service)
    {
        abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
        $data=$request->validate([
            'journal_line_id'=>['required','integer','exists:journal_lines,id'],
            'reconciliation_reference'=>['required','string','min:4','max:180'],
            'evidence_notes'=>['required','string','min:15','max:3000'],
        ]);
        $service->link($asset,$data,$request->user()->id);
        return back()->with('success','Original PPE acquisition debit reconciled. No duplicate asset acquisition journal was created.');
    }

    public function assign(Request $request, FixedAsset $asset, FixedAssetAssignmentService $service)
    {
        $this->updateAccess($request);
        $data = $request->validate([
            'employee_profile_id' => ['required', 'exists:employee_profiles,id'],
            'assigned_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->assign($asset, EmployeeProfile::findOrFail($data['employee_profile_id']), $data['assigned_on'], $data['notes'] ?? null, $request->user()->id);
        return back()->with('success', 'Asset assigned with history recorded.');
    }

    public function returnAssignment(Request $request, FixedAsset $asset, FixedAssetAssignment $assignment, FixedAssetAssignmentService $service)
    {
        $this->updateAccess($request);
        $data = $request->validate(['returned_on' => ['required', 'date', 'before_or_equal:today']]);
        $service->returnAssignment($asset, $assignment, $data['returned_on'], $request->user()->id);
        return back()->with('success', 'Asset return recorded.');
    }

    private function viewAccess(Request $request): void { abort_unless($request->user()->canAny(['maintenance.view', 'finance.view', 'administration.view']), 403); }
    private function createAccess(Request $request): void { abort_unless($request->user()->canAny(['maintenance.create', 'finance.update', 'administration.update']), 403); }
    private function updateAccess(Request $request): void { abort_unless($request->user()->canAny(['maintenance.update', 'finance.update', 'administration.update']), 403); }

    private function normalize(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        return str_replace(',', '', strtr($value, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>',',
        ]));
    }
}
