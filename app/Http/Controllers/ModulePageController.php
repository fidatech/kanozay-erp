<?php

namespace App\Http\Controllers;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

class ModulePageController extends Controller
{
    public function show(Request $request, string $module, string $page)
    {
        if (isset((config('operational_registers')??[])[$module.'.'.$page])) {
            return app(OperationalRegisterController::class)->index($request,$module,$page);
        }

        if ($module === 'inventory' && in_array($page,['transfers','warehouses'],true)) {
            return app(WarehouseStockController::class)->index($request,app(\App\Services\WarehouseStockService::class));
        }

        if ($module === 'manufacturing' && in_array($page,['equipment','downtime','scheduling','plans','work-orders'],true)) {
            return app(ManufacturingOperationsController::class)->index($request,$page);
        }

        if ($module === 'laboratory' && in_array($page,
            ['samples','tests','specifications','incoming','in-process','finished','nonconformances','certificates','holds','inspections'],true)) {
            return app(LaboratoryQualityController::class)->index($request,$page);
        }

        if ($module==='sales' && in_array($page,['pricing','customer-credit-limits'],true)) {
            return app(CustomerCommercialPolicyController::class)->index($request,$page);
        }

        if (($module==='inventory' && in_array($page,['reorder','material-requests'],true)) ||
            ($module==='purchases' && $page==='requisitions')) {
            return app(ProcurementRequestController::class)->index($request,$module,$page);
        }

        if ($module === 'purchases' && $page === 'goods-receipts') {
            return app(MatchedSupplierInvoiceController::class)->index($request,app(\App\Services\MatchedSupplierInvoiceService::class));
        }

        if ($module === 'purchases' && $page === 'supplier-debit-notes') {
            return app(SupplierDebitNoteController::class)->index($request);
        }

        if ($module === 'purchases' && $page === 'purchase-returns') {
            return app(PurchaseReturnController::class)->index($request,app(\App\Services\PurchaseReturnService::class));
        }

        if ($module === 'assets' && in_array($page,['maintenance','schedules','service-history','transfers','disposals'],true)) {
            return app(FixedAssetLifecycleController::class)->index($request,$page);
        }

        if ($module === 'assets' && $page === 'depreciation') {
            return app(FixedAssetDepreciationController::class)->index($request);
        }

        if ($module === 'sales' && $page === 'delivery-notes') {
            return app(SalesDeliveryNoteController::class)->index($request);
        }

        if ($module === 'documents' && in_array($page,['library','categories','approvals','versions','templates','retention','expirations'],true)) {
            return app(ManagedDocumentController::class)->index($request,$page);
        }

        if ($module === 'crm' && config('crm_workflows.'.$page) !== null) {
            return app(CrmController::class)->index($request, $page);
        }

        if ($module === 'inventory' && $page === 'batch-tracking') {
            return app(RawMaterialQualityHoldController::class)->index($request,app(\App\Services\QualityMaterialHoldService::class));
        }

        if ($module === 'inventory' && $page === 'counts') {
            return app(InventoryStockCountController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'employees') {
            return app(EmployeeProfileController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'benefits') {
            return app(PayrollComponentController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'payroll') {
            return app(PayrollPeriodController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'attendance') {
            return app(EmployeeAttendanceController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'leave') {
            return app(EmployeeLeaveController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'departments') {
            return app(EmployeeDepartmentController::class)->index($request);
        }

        if ($module === 'hr' && $page === 'advances') {
            return app(EmployeeSalaryAdvanceController::class)->index($request);
        }

        if ($module === 'assets' && $page === 'register') {
            return app(FixedAssetController::class)->index($request);
        }

        if ($module === 'shareholders' && in_array($page,['ownership','resolutions','distributions'],true)) {
            return app(ShareholderGovernanceController::class)->index($request,$page);
        }

        if ($module === 'shareholders' && in_array($page, ['register', 'capital', 'statements'], true)) {
            return app(ShareholderController::class)->index($request);
        }

        $modules = config('erp_pages', []);
        abort_unless(isset($modules[$module]['pages'][$page]) || ($page === 'overview' && isset($modules[$module])), 404);

        $permission = match ($module) {
            'assets' => 'maintenance.view',
            'crm' => 'sales.view',
            'documents' => 'administration.view',
            default => $module.'.view',
        };
        abort_unless($request->user()->canAny([$permission, 'administration.view']), 403);

        if ($module === 'manufacturing' && $page === 'orders') {
            return app(ProductionOrderController::class)->index($request);
        }

        if ($module === 'manufacturing' && $page === 'recipes') {
            return app(ProductionRecipeController::class)->index($request);
        }

        if ($module === 'purchases' && $page === 'orders') {
            return app(PurchaseOrderController::class)->index($request);
        }

        if ($module === 'reports' && $page !== 'overview') {
            return app(ReportCenterController::class)->show($request, $page);
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
