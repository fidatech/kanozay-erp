<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\EmployeeProfile;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $filters['from'] ?? now()->subDays(29)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $start = $from . ' 00:00:00';
        $end = $to . ' 23:59:59';
        $user = $request->user();
        $cards = [];
        $recent = [];
        $releasedPackages = null;

        if ($user->canAny(['administration.view', 'purchases.view'])) {
            $purchaseCount = PurchaseOrder::whereBetween('order_date', [$from, $to])->count();
            $orders = PurchaseOrder::with('supplier:id,name')->whereBetween('order_date', [$from, $to])->latest('order_date')->limit(10)->get();
            $cards[] = ['label' => 'Purchase orders', 'value' => $purchaseCount, 'hint' => 'Created in selected period', 'icon' => 'fa-cart-shopping', 'url' => route('purchases.orders.index')];
            foreach ($orders as $order) {
                $recent[] = ['date' => $order->order_date, 'type' => 'Purchase order', 'reference' => $order->number, 'party' => $order->supplier?->name ?? '—', 'status' => ucfirst(str_replace('_', ' ', $order->status)), 'url' => route('purchases.orders.show', $order)];
            }
        }

        if ($user->canAny(['administration.view', 'manufacturing.view'])) {
            $planCount = ProductionOrder::whereBetween('created_at', [$start, $end])->count();
            $orders = ProductionOrder::whereBetween('created_at', [$start, $end])->latest()->limit(10)->get();
            $cards[] = ['label' => 'Production plans', 'value' => $planCount, 'hint' => 'Created in selected period', 'icon' => 'fa-industry', 'url' => route('production.orders.index')];
            foreach ($orders as $order) {
                $recent[] = ['date' => $order->created_at->toDateString(), 'type' => 'Production plan', 'reference' => $order->number, 'party' => $order->planned_recipe_name ?? '—', 'status' => ucfirst(str_replace('_', ' ', $order->status)), 'url' => route('production.orders.show', $order)];
            }
            $releasedPackages = ProductionBatch::whereNotNull('released_at')->whereBetween('released_at', [$start, $end])->sum('accepted_packages');
            $cards[] = ['label' => 'Released packages', 'value' => number_format((int) $releasedPackages), 'hint' => 'QC-released in selected period', 'icon' => 'fa-box', 'url' => route('production.orders.index')];
        }

        if ($user->canAny(['administration.view', 'laboratory.view'])) {
            $pendingQc = ProductionBatch::where('status', 'pending_qc')->whereBetween('created_at', [$start, $end])->count();
            $cards[] = ['label' => 'Batches awaiting QC', 'value' => $pendingQc, 'hint' => 'Created in selected period', 'icon' => 'fa-flask', 'url' => route('production.orders.index')];
        }

        if ($user->canAny(['administration.view', 'inventory.view'])) {
            $cards[] = ['label' => 'Raw material items', 'value' => InventoryMaterial::where('is_active', true)->count(), 'hint' => 'Active catalog records', 'icon' => 'fa-boxes-stacked', 'url' => route('inventory.materials.index')];
        }

        if ($user->canAny(['administration.view', 'hr.view'])) {
            $cards[] = ['label' => 'Active employee profiles', 'value' => EmployeeProfile::where('is_active', true)->count(), 'hint' => 'Registered profiles', 'icon' => 'fa-users', 'url' => route('workspace.page', ['hr', 'employees'])];
        }

        if ($user->canAny(['administration.view', 'finance.view'])) {
            $journalCount = JournalEntry::where('status', 'posted')->whereBetween('entry_date', [$from, $to])->count();
            $journals = JournalEntry::with('currency')->where('status', 'posted')->whereBetween('entry_date', [$from, $to])->latest('entry_date')->limit(10)->get();
            $cards[] = ['label' => 'Posted journals', 'value' => $journalCount, 'hint' => 'Posted in selected period', 'icon' => 'fa-book', 'url' => route('finance.journals.index')];
            foreach ($journals as $journal) {
                $recent[] = ['date' => $journal->entry_date->toDateString(), 'type' => 'Posted journal', 'reference' => $journal->number, 'party' => $journal->description, 'status' => $journal->currency?->code ?? '—', 'url' => route('finance.journals.index')];
            }
        }

        if ($user->can('administration.update') || $user->hasRole('Super Administrator')) {
            $pendingApprovals = ApprovalRequest::where('status', 'pending')->whereBetween('created_at', [$start, $end])->count();
        } else {
            $pendingApprovals = ApprovalRequest::where('requested_by', $user->id)->where('status', 'pending')->whereBetween('created_at', [$start, $end])->count();
        }
        if ($user->canAny(['administration.view', 'administration.update'])) {
            $cards[] = ['label' => 'Pending approvals', 'value' => $pendingApprovals, 'hint' => 'Submitted in selected period', 'icon' => 'fa-file-circle-check', 'url' => route('approvals.index')];
        }

        usort($recent, fn ($a, $b) => strcmp($b['date'], $a['date']));
        return view('dashboard', [
            'cards' => $cards,
            'recent' => array_slice($recent, 0, 12),
            'from' => $from,
            'to' => $to,
            'releasedPackages' => $releasedPackages,
        ]);
    }
}
