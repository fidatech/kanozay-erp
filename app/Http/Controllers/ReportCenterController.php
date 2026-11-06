<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceiptLandedCost;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\PayrollPeriod;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Models\ProductionBatchCost;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportCenterController extends Controller
{
    /**
     * Operational records, with their original currencies and source links.
     * No invented valuation, conversion, or GL posting is performed here.
     */
    public function show(Request $request, string $report)
    {
        abort_unless($request->user()->canAny(['administration.view', 'reports.view']), 403);

        if ($report === 'profit-loss') {
            return app(ProfitAndLossController::class)->index($request);
        }

        if ($report === 'balance-sheet') {
            return app(BalanceSheetController::class)->index($request);
        }

        if ($report === 'cash-flow') {
            return app(CashMovementReportController::class)->index($request);
        }

        $definition = $this->definition($report);
        abort_if($definition === null, 404);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);

        /** @var Builder $query */
        $query = $definition['query'];
        $dateColumn = $definition['date'];
        if ($dateColumn !== null) {
            if (! empty($filters['from'])) {
                $query->whereDate($dateColumn, '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->whereDate($dateColumn, '<=', $filters['to']);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '' && ! empty($definition['search'])) {
            $columns = $definition['search'];
            $query->where(function (Builder $q) use ($columns, $search) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $columns = $definition['columns'];
        $formatter = $definition['format'];
        $title = config('erp_pages.reports.pages.'.$report);
        $note = $definition['note'];

        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query, $columns, $formatter) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $columns);

                (clone $query)->reorder('id')->chunkById(250, function ($chunk) use ($out, $formatter) {
                    foreach ($chunk as $record) {
                        $row = $formatter($record);
                        fputcsv($out, array_map([self::class, 'csvSafe'], $row['cells']));
                    }
                });

                fclose($out);
            }, 'kanozay-'.Str::slug($report).'-'.now()->format('Ymd').'.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $records->setCollection($records->getCollection()->map($formatter));

        return view('reports.register', compact(
            'report', 'title', 'note', 'filters', 'dateColumn', 'columns', 'records'
        ));
    }

    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        // Spreadsheet applications can execute formulas from CSV cells.
        return preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
    }

    private function definition(string $report): ?array
    {
        return match ($report) {
            'production' => [
                'query' => ProductionOrder::query()->with('batch'),
                'date' => 'created_at', 'search' => ['number', 'recipe_name_snapshot'],
                'columns' => ['Production order', 'Recipe', 'Packages planned', 'Order status', 'Batch', 'QC / release'],
                'note' => 'Production plans and their associated batch decisions. Dates refer to when the plan was recorded.',
                'format' => fn (ProductionOrder $r) => $this->row([
                    $r->number, $r->planned_recipe_name ?? 'Unknown recipe', (string) $r->package_count,
                    $r->status, $r->batch?->batch_number ?? 'Not produced',
                    $r->batch?->status ?? 'No batch',
                ], route('production.orders.show', $r)),
            ],
            'inventory' => [
                'query' => InventoryMaterial::query(),
                'date' => null, 'search' => ['sku', 'name'],
                'columns' => ['SKU', 'Material', 'Unit', 'On hand', 'Reserved', 'Available', 'Type'],
                'note' => 'Current company-wide stock snapshot. Date filters do not apply; individual warehouse stock is not tracked by this ledger.',
                'format' => fn (InventoryMaterial $r) => $this->row([
                    $r->sku, $r->name, $r->unit, (string) $r->on_hand_quantity,
                    (string) $r->reserved_quantity, (string) $r->available_quantity,
                    $r->is_packaging ? 'Packaging' : 'Raw material',
                ], route('inventory.materials.show', $r)),
            ],
            'purchasing' => [
                'query' => PurchaseOrder::query()->with(['supplier', 'currency'])->withCount('receipts'),
                'date' => 'order_date', 'search' => ['number'],
                'columns' => ['Purchase order', 'Supplier', 'Currency', 'Order date', 'Source', 'Status', 'Receipts'],
                'note' => 'Purchase order register, including status and posted receipt count. This is not a verified supplier payable report.',
                'format' => fn (PurchaseOrder $r) => $this->row([
                    $r->number, $r->supplier?->name ?? 'Unknown', $r->currency?->code ?? '',
                    (string) $r->order_date, $r->source, $r->status, (string) $r->receipts_count,
                ], route('purchases.orders.show', $r)),
            ],
            'sales' => [
                'query' => SalesInvoice::query()->with('customer'),
                'date' => 'invoice_date', 'search' => ['number'],
                'columns' => ['Invoice', 'Customer', 'Date', 'Due date', 'Currency', 'Total', 'Status'],
                'note' => 'Invoice register. Amounts are shown in their own invoice currency and are never summed across currencies.',
                'format' => fn (SalesInvoice $r) => $this->row([
                    $r->number, $r->customer?->name ?? 'Unknown',
                    $r->invoice_date?->toDateString() ?? '', $r->due_date?->toDateString() ?? '',
                    $r->currency_code, (string) $r->total, $r->status,
                ], route('sales.invoices.show', $r)),
            ],
            'quality' => [
                'query' => ProductionBatchCheck::query()->with(['batch.order']),
                'date' => 'created_at', 'search' => ['test_name'],
                'columns' => ['Test date', 'Batch', 'Production order', 'Test', 'Result', 'Specification', 'Observation'],
                'note' => 'Immutable laboratory test observations, including failed tests. No test result is inferred.',
                'format' => fn (ProductionBatchCheck $r) => $this->row([
                    $r->created_at?->format('Y-m-d') ?? '',
                    $r->batch?->batch_number ?? '',
                    $r->batch?->order?->number ?? '',
                    $r->test_name, strtoupper($r->result),
                    $r->specification ?? '', $r->observation ?? '',
                ], $r->batch?->production_order_id
                    ? route('production.orders.show', $r->batch->production_order_id) : null),
            ],
            'finance' => [
                'query' => JournalEntry::query()->with('currency'),
                'date' => 'entry_date', 'search' => ['number', 'description'],
                'columns' => ['Journal', 'Date', 'Currency', 'Status', 'Reference', 'Description'],
                'note' => 'Journal register, with draft and posted status distinguished. Use the general ledger for posted monetary balances.',
                'format' => fn (JournalEntry $r) => $this->row([
                    $r->number, $r->entry_date?->toDateString() ?? '',
                    $r->currency?->code ?? '', $r->status, $r->reference ?? '', $r->description ?? '',
                ], route('finance.journals.index')),
            ],
            'hr' => [
                'query' => PayrollPeriod::query()->with('currency')->withCount('lines'),
                'date' => 'period_start', 'search' => [],
                'columns' => ['Payroll month', 'Currency', 'Status', 'Employee lines', 'Approved on'],
                'note' => 'Payroll run register. No tax or attendance deduction is calculated by this report.',
                'format' => fn (PayrollPeriod $r) => $this->row([
                    $r->period_start?->format('Y-m') ?? '', $r->currency?->code ?? '',
                    $r->status, (string) $r->lines_count, $r->approved_at?->format('Y-m-d') ?? '',
                ], route('hr.payroll.show', $r)),
            ],
            'costing' => [
                'query' => ProductionBatchCost::query()->with(['batch.order']),
                'date' => 'created_at', 'search' => [],
                'columns' => ['Batch', 'Production order', 'Currency', 'Raw-material cost', 'Costed quantity', 'Unvalued quantity'],
                'note' => 'Frozen raw-material cost snapshots for released batches, separated by source currency. This does not include labor, factory overhead or a complete finished-goods unit cost.',
                'format' => fn (ProductionBatchCost $r) => $this->row([
                    $r->batch?->batch_number ?? '',
                    $r->batch?->order?->number ?? '',
                    $r->currency_code ?? 'Unvalued',
                    $r->material_cost === null ? 'Unknown' : (string) $r->material_cost,
                    (string) $r->consumed_quantity, (string) $r->unvalued_quantity,
                ], $r->batch?->production_order_id
                    ? route('production.orders.show', $r->batch->production_order_id) : null),
            ],
            'landed-costs' => [
                'query' => GoodsReceiptLandedCost::query()->with('receipt.purchaseOrder'),
                'date' => 'created_at', 'search' => ['component', 'reference'],
                'columns' => ['GRN', 'Purchase order', 'Component', 'Currency', 'Amount', 'Reference'],
                'note' => 'Immutable landed-cost allocation components. These amounts have not been posted as supplier bills or general-ledger expenses.',
                'format' => fn (GoodsReceiptLandedCost $r) => $this->row([
                    $r->receipt?->number ?? '', $r->receipt?->purchaseOrder?->number ?? '',
                    $r->component, $r->currency_code, (string) $r->amount, $r->reference ?? '',
                ], $r->receipt?->purchase_order_id
                    ? route('purchases.orders.show', $r->receipt->purchase_order_id) : null),
            ],
            'traceability' => [
                'query' => ProductionBatch::query()->with(['order', 'finishedGood', 'dispatchLines']),
                'date' => 'created_at', 'search' => ['batch_number'],
                'columns' => ['Batch', 'Product SKU', 'Production order', 'QC status', 'Reported packages', 'Accepted packages', 'Rejected packages', 'Gross dispatched'],
                'note' => 'Batch to production-order and dispatch trace. Gross dispatched packages do not subtract customer returns. Supplier raw-material lot trace remains incomplete.',
                'format' => fn (ProductionBatch $r) => $this->row([
                    $r->batch_number, $r->finishedGood?->sku ?? '',
                    $r->order?->number ?? '', $r->status,
                    (string) $r->reported_packages,
                    (string) $r->accepted_packages, (string) $r->rejected_packages,
                    (string) $r->dispatchLines->sum('quantity_packages'),
                ], $r->production_order_id
                    ? route('production.orders.show', $r->production_order_id) : null),
            ],
            default => null,
        };
    }

    private function row(array $cells, ?string $url): array
    {
        return ['cells' => $cells, 'url' => $url];
    }
}
