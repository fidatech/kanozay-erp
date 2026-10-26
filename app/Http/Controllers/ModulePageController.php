<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ModulePageController extends Controller
{
    public function show(Request $request, string $module, string $page)
    {
        abort_unless($request->user()->can('administration.view'), 403);
        $modules = config('erp_pages', []);
        abort_unless(isset($modules[$module]['pages'][$page]) || ($page === 'overview' && isset($modules[$module])), 404);

        if ($page === 'overview') {
            return view('modules.overview', [
                'module' => $module,
                'moduleData' => $modules[$module],
                'recentActivity' => \App\Models\ActivityLog::query()->latest('id')->limit(5)->get(),
            ]);
        }

        return view('modules.page', [
            'module' => $module,
            'page' => $page,
            'moduleData' => $modules[$module],
            'pageTitle' => $modules[$module]['pages'][$page],
        ]);
    }
}
