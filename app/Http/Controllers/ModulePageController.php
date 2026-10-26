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

        if ($module === 'purchases' && $page === 'orders') {
            return app(PurchaseOrderController::class)->index($request);
        }

        if ($page === 'overview') {
            return view('modules.overview', [
                'module' => $module,
                'moduleData' => $modules[$module],
                'purchaseSnapshot' => $module === 'purchases' ? [
                    'suppliers' => \App\Models\TradeParty::query()->where('type', 'supplier')->where('is_active', true)->count(),
                    'payables' => \App\Models\TradeDocument::query()->where('type', 'payable')->count(),
                    'overdue' => \App\Models\TradeDocument::query()->where('type', 'payable')->whereDate('due_date', '<', today())->whereRaw('amount > (select coalesce(sum(amount), 0) from trade_settlement_allocations where trade_document_id = trade_documents.id)')->count(),
                    'recent' => \App\Models\TradeDocument::query()->with(['party', 'currency'])->where('type', 'payable')->latest('id')->limit(5)->get(),
                ] : null,
                'salesSnapshot' => $module === 'sales' ? [
                    'customers' => \App\Models\TradeParty::query()->where('type', 'customer')->where('is_active', true)->count(),
                    'receivables' => \App\Models\TradeDocument::query()->where('type', 'receivable')->count(),
                    'overdue' => \App\Models\TradeDocument::query()->where('type', 'receivable')->whereDate('due_date', '<', today())->whereRaw('amount > (select coalesce(sum(amount), 0) from trade_settlement_allocations where trade_document_id = trade_documents.id)')->count(),
                    'recent' => \App\Models\TradeDocument::query()->with(['party', 'currency'])->where('type', 'receivable')->latest('id')->limit(5)->get(),
                ] : null,
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
