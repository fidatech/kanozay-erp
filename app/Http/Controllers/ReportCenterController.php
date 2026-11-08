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
use App\Models\SalesDeliveryNote;
use App\Models\SalesCreditNote;
use App\Models\QualitySample;
use App\Models\QualityNonconformance;
use App\Support\ErpInterfaceLabels;
use App\Support\ReportingLabels;
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

        $columns = array_map([ReportingLabels::class,'column'],$definition['columns']);
        $formatter = $definition['format'];
        $title = ErpInterfaceLabels::page('reports',$report);
        $note = $definition['note'];

        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query, $columns, $formatter) {
                $out = fopen('php://output', 'w');
                fwrite($out,"\xEF\xBB\xBF");
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
                'note' => 'Current company-wide material on-hand and reservation snapshot. Individual warehouse balances and FIFO lot locations are tracked in the warehouse stock and valuation registers. Date filters do not apply here.',
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
                'note' => 'Production-batch quick checks, including failures. Formal collected laboratory sample results and independently reviewed specifications are listed in the separate Laboratory Sample report.',
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
                'columns' => ['Batch', 'Production order', 'Currency', 'Ingredient cost', 'Packaging cost', 'Total material cost', 'Costed quantity', 'Unvalued quantity'],
                'note' => 'Frozen material cost snapshots, with ingredients and packaging separated where the source record captured them. Legacy category splits remain unknown. No cross-currency aggregation, complete labor/overhead valuation or fabricated finished-goods unit cost is provided.',
                'format' => fn (ProductionBatchCost $r) => $this->row([
                    $r->batch?->batch_number ?? '',
                    $r->batch?->order?->number ?? '',
                    $r->currency_code ?? 'Unvalued',
                    $r->ingredient_cost === null ? 'Legacy / unavailable' : (string)$r->ingredient_cost,
                    $r->packaging_cost === null ? 'Legacy / unavailable' : (string)$r->packaging_cost,
                    $r->material_cost === null ? 'Unknown' : (string)$r->material_cost,
                    (string)$r->consumed_quantity,(string)$r->unvalued_quantity,
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
                'note' => 'Finished-batch status and original gross dispatch. Gross dispatch does not subtract customer-credit returns. Detailed original supplier/GRN/FIFO input-lot provenance is available from batch and reverse source-lot traceability; historical source gaps remain explicitly unresolved.',
                'format' => fn (ProductionBatch $r) => $this->row([
                    $r->batch_number, $r->finishedGood?->sku ?? '',
                    $r->order?->number ?? '', $r->status,
                    (string) $r->reported_packages,
                    (string) $r->accepted_packages, (string) $r->rejected_packages,
                    (string) $r->dispatchLines->sum('quantity_packages'),
                ], $r->production_order_id
                    ? route('production.orders.show', $r->production_order_id) : null),
            ],
            'delivery-notes'=>[
                'query'=>SalesDeliveryNote::query()->with(['invoice','deliveryProof']),
                'date'=>'delivery_date','search'=>['number','received_by','reference'],
                'columns'=>['Delivery note','Original invoice','Delivery date','Status','Received by','Proof reference'],
                'note'=>'Delivery notes describe logistics documentation and customer-confirmed receipt, not additional inventory issues. Returned and cancelled notes remain visible for audit.',
                'format'=>fn(SalesDeliveryNote $r)=>$this->row([
                    $r->number,$r->invoice?->number??'',
                    $r->delivery_date?->toDateString()??'',
                    $r->status,$r->received_by??'Awaiting receipt',
                    $r->deliveryProof?->proof_reference??'No customer receipt evidence',
                ],route('sales.delivery-notes.show',$r)),
            ],
            'sales-credits'=>[
                'query'=>SalesCreditNote::query()->with('invoice'),
                'date'=>'credit_date','search'=>['number','reason'],
                'columns'=>['Credit note','Original invoice','Credit date','Currency','Amount','Description'],
                'note'=>'Posted customer credit notes in their source currency. A credit reverses an invoiced amount but does not prove cash refund or the physical return of saleable finished-goods stock.',
                'format'=>fn(SalesCreditNote $r)=>$this->row([
                    $r->number,$r->invoice?->number??'',
                    $r->credit_date?->toDateString()??'',
                    $r->currency_code,(string)$r->total,$r->reason,
                ],route('sales.credit-notes.show',$r)),
            ],
            'qc-samples'=>[
                'query'=>QualitySample::query(),
                'date'=>'collected_on','search'=>['number','source_type','status'],
                'columns'=>['Sample','Source','Date','Status','Reference'],
                'note'=>'Formal source-linked laboratory samples. Approval reflects the current review decision; required specification versions were frozen for new samples, while legacy sample history may lack that evidence.',
                'format'=>fn(QualitySample $r)=>$this->row([
                    $r->number,$r->source_type,
                    $r->collected_on?->toDateString()??'',
                    $r->status,
                    $r->production_batch_id?'Production batch #'.$r->production_batch_id:'GRN line #'.$r->goods_receipt_line_id,
                ],route('laboratory.samples.show',$r->id)),
            ],
            'nonconformances'=>[
                'query'=>QualityNonconformance::query(),
                'date'=>'opened_on','search'=>['number','severity','status'],
                'columns'=>['NCR','Severity','Opened','Review due','Status','Description'],
                'note'=>'Recorded quality nonconformance investigations and corrective actions. Closing an investigation never directly releases rejected materials, a held FIFO lot or affected finished goods.',
                'format'=>fn(QualityNonconformance $r)=>$this->row([
                    $r->number,$r->severity,
                    $r->opened_on?->toDateString()??'',
                    $r->due_on?->toDateString()??'',
                    $r->status,$r->description,
                ],route('workspace.page',['laboratory','nonconformances'])),
            ],
            default => null,
        };
    }

    private function row(array $cells, ?string $url): array
    {
        return ['cells' => $cells, 'url' => $url];
    }
}
