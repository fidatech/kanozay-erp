<?php

namespace App\Http\Controllers;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

class ModulePageController extends Controller
{
    public function show(Request $request, string $module, string $page)
    {
        abort_unless($request->user()->can('administration.view'), 403);
        $modules = config('erp_pages', []);
        abort_unless(isset($modules[$module]['pages'][$page]) || ($page === 'overview' && isset($modules[$module])), 404);

        if ($module === 'manufacturing' && $page === 'orders') {
            return app(ProductionOrderController::class)->index($request);
        }

        if ($module === 'manufacturing' && $page === 'recipes') {
            return app(ProductionRecipeController::class)->index($request);
        }

        if ($module === 'purchases' && $page === 'orders') {
            return app(PurchaseOrderController::class)->index($request);
        }

        if ($page === 'overview') {
            $snapshot = function (string $partyType, string $documentType): array {
                $baseQuery = \App\Models\TradeDocument::query()->where('type', $documentType);
                $overdueDocuments = (clone $baseQuery)->whereDate('due_date', '<', today())->with('allocations')->get();
                $overdue = $overdueDocuments->filter(function ($document) {
                    $allocated = BigDecimal::zero();
                    foreach ($document->allocations as $allocation) $allocated = $allocated->plus((string) $allocation->amount);
                    return BigDecimal::of((string) $document->amount)->compareTo($allocated) > 0;
                })->count();
                return [
                    'partyCount' => \App\Models\TradeParty::query()->where('type', $partyType)->where('is_active', true)->count(),
                    'documentCount' => (clone $baseQuery)->count(),
                    'overdue' => $overdue,
                    'recent' => (clone $baseQuery)->with(['party', 'currency'])->latest('id')->limit(5)->get(),
                ];
            };
            $purchaseData = $module === 'purchases' ? $snapshot('supplier', 'payable') : null;
            $salesData = $module === 'sales' ? $snapshot('customer', 'receivable') : null;
            return view('modules.overview', [
                'module' => $module,
                'moduleData' => $modules[$module],
                'purchaseSnapshot' => $purchaseData === null ? null : [
                    'suppliers' => $purchaseData['partyCount'],
                    'payables' => $purchaseData['documentCount'],
                    'overdue' => $purchaseData['overdue'],
                    'recent' => $purchaseData['recent'],
                ],
                'salesSnapshot' => $salesData === null ? null : [
                    'customers' => $salesData['partyCount'],
                    'receivables' => $salesData['documentCount'],
                    'overdue' => $salesData['overdue'],
                    'recent' => $salesData['recent'],
                ],
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
