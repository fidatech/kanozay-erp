<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CompanySetting;
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
        return view('admin.company.edit', ['company' => CompanySetting::first()]);
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
        ]);
        DB::transaction(function () use ($data, $request) {
            $company = CompanySetting::first();
            if ($company) $company->update($data + ['updated_by' => $request->user()->id]);
            else $company = CompanySetting::create($data + ['updated_by' => $request->user()->id]);
            ActivityLog::record('company.updated', 'company', $company->id, 'Company profile settings updated');
        });
        return redirect()->route('admin.company.edit')->with('status','Company settings saved successfully.');
    }
}
