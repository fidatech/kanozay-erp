<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetAssignmentService;
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
        return view('assets.show', [
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
