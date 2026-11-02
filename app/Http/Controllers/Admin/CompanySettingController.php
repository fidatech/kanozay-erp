<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CompanySetting;
use App\Models\CompanyBranch;
use App\Models\CompanyWarehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanySettingController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->can('administration.view'), 403);
        return view('admin.company.edit', ['company' => CompanySetting::first(), 'branches' => CompanyBranch::with('warehouses')->orderBy('name')->get()]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate([
            'name' => ['required','string','max:180'],
            'legal_name' => ['nullable','string','max:180'],
            'registration_number' => ['nullable','string','max:100'],
            'tax_number' => ['nullable','string','max:100'],
            'email' => ['nullable','email','max:180'],
            'phone' => ['nullable','string','max:60'],
            'website' => ['nullable','url','max:180'],
            'address' => ['nullable','string','max:1000'],
            'city' => ['nullable','string','max:100'],
            'country' => ['required','string','max:100'],
            'timezone' => ['required',Rule::in(['Asia/Kabul','UTC'])],
            'default_locale' => ['required',Rule::in(['en','fa','ps'])],
            'date_format' => ['required',Rule::in(['gregorian','jalali'])],
            'fiscal_start_month' => ['nullable','required_with:fiscal_start_day','integer','between:1,12'],
            'fiscal_start_day' => ['nullable','required_with:fiscal_start_month','integer','between:1,31'],
        ]);
        if (isset($data['fiscal_start_month'], $data['fiscal_start_day']) && !checkdate((int)$data['fiscal_start_month'], (int)$data['fiscal_start_day'], 2024)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['fiscal_start_day' => 'Invalid fiscal year start date.']);
        }
        DB::transaction(function () use ($data, $request) {
            $company = CompanySetting::first();
            if ($company) $company->update($data + ['updated_by' => $request->user()->id]);
            else $company = CompanySetting::create($data + ['updated_by' => $request->user()->id]);
            ActivityLog::record('company.updated', 'company', $company->id, 'Company profile settings updated');
        });
        return redirect()->route('admin.company.edit')->with('status','Company settings saved successfully.');
    }
    public function storeBranch(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate(['code'=>['required','string','max:30',Rule::unique('company_branches')], 'name'=>['required','string','max:180'],'address'=>['nullable','string','max:500']]);
        DB::transaction(function () use ($data) {
            $branch=CompanyBranch::create($data);
            ActivityLog::record('company.branch.created','company_branch',$branch->id,'Branch created: '.$branch->code);
        });
        return back()->with('status','Branch created successfully.');
    }

    public function updateBranch(Request $request, CompanyBranch $branch): RedirectResponse
    {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['name'=>['required','string','max:180'],'address'=>['nullable','string','max:500'],'is_active'=>['required','boolean']]);
        if (!$data['is_active'] && $branch->warehouses()->where('is_active',true)->exists()) return back()->withErrors(['branch'=>'Deactivate the branch warehouses first.']);
        DB::transaction(function () use ($branch,$data) { $branch->update($data); ActivityLog::record('company.branch.updated','company_branch',$branch->id,'Branch updated: '.$branch->code); });
        return back()->with('status','Branch updated successfully.');
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['company_branch_id'=>['required',Rule::exists('company_branches','id')->where('is_active',true)],'code'=>['required','string','max:30',Rule::unique('company_warehouses')],'name'=>['required','string','max:180']]);
        DB::transaction(function () use ($data) { $warehouse=CompanyWarehouse::create($data); ActivityLog::record('company.warehouse.created','company_warehouse',$warehouse->id,'Warehouse created: '.$warehouse->code); });
        return back()->with('status','Warehouse created successfully.');
    }

    public function updateWarehouse(Request $request, CompanyWarehouse $warehouse): RedirectResponse
    {
        abort_unless($request->user()->can('administration.update'),403);
        $data=$request->validate(['name'=>['required','string','max:180'],'is_active'=>['required','boolean']]);
        if ($data['is_active'] && !$warehouse->branch->is_active) return back()->withErrors(['warehouse'=>'Cannot activate a warehouse in an inactive branch.']);
        DB::transaction(function () use ($warehouse,$data) { $warehouse->update($data); ActivityLog::record('company.warehouse.updated','company_warehouse',$warehouse->id,'Warehouse updated: '.$warehouse->code); });
        return back()->with('status','Warehouse updated successfully.');
    }

}
