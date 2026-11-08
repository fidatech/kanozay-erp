<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\FinancialAccount;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashMovementReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'reports.view']), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
            'type' => ['nullable', Rule::in(['cashbox', 'bank', 'sarafi'])],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $accounts = FinancialAccount::with('currency')
            ->when($filters['currency_id'] ?? null, fn ($q, $id) => $q->where('currency_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderBy('type')->orderBy('name')->get();
        $rows = [];
        foreach ($accounts as $account) {
            $rows[$account->id] = [
                'account' => $account,
                'currency' => $account->currency,
                'opening' => BigDecimal::zero(),
                'inflows' => BigDecimal::zero(),
                'outflows' => BigDecimal::zero(),
                'closing' => BigDecimal::zero(),
                'mapped' => false,
            ];
        }

        if ($accounts->isNotEmpty()) {
            $mappings = DB::table('entity_ledger_accounts')
                ->where('purpose', 'cash')
                ->whereIn('entity_type', ['cashbox', 'bank', 'sarafi'])
                ->whereIn('entity_id', $accounts->pluck('id'))
                ->get(['entity_type', 'entity_id', 'currency_id', 'ledger_account_id']);
            $accountByLedger = [];
            foreach ($mappings as $mapping) {
                if (isset($rows[$mapping->entity_id]) && $rows[$mapping->entity_id]['account']->type === $mapping->entity_type
                    && (int) $rows[$mapping->entity_id]['account']->currency_id === (int) $mapping->currency_id) {
                    $accountByLedger[$mapping->ledger_account_id] = ['account_id' => (int) $mapping->entity_id, 'currency_id' => (int) $mapping->currency_id];
                    $rows[$mapping->entity_id]['mapped'] = true;
                }
            }
            if ($accountByLedger !== []) {
                $lines = DB::table('journal_lines as jl')
                    ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                    ->where('je.status', 'posted')
                    ->whereIn('jl.ledger_account_id', array_keys($accountByLedger))
                    ->whereDate('je.entry_date', '<=', $to)
                    ->select('jl.ledger_account_id', 'je.currency_id', 'je.entry_date', 'jl.debit', 'jl.credit')->get();
                foreach ($lines as $line) {
                    $mapping = $accountByLedger[$line->ledger_account_id] ?? null;
                    if ($mapping === null || (int) $line->currency_id !== $mapping['currency_id']) {
                        continue;
                    }
                    $accountId = $mapping['account_id'];
                    if ($line->entry_date < $from) {
                        $rows[$accountId]['opening'] = $rows[$accountId]['opening']->plus((string) $line->debit)->minus((string) $line->credit);
                    } else {
                        $rows[$accountId]['inflows'] = $rows[$accountId]['inflows']->plus((string) $line->debit);
                        $rows[$accountId]['outflows'] = $rows[$accountId]['outflows']->plus((string) $line->credit);
                    }
                }
            }
        }

        foreach ($rows as &$row) {
            $row['closing'] = $row['opening']->plus($row['inflows'])->minus($row['outflows']);
            $row['opening'] = (string) $row['opening'];
            $row['inflows'] = (string) $row['inflows'];
            $row['outflows'] = (string) $row['outflows'];
            $row['closing'] = (string) $row['closing'];
        }
        unset($row);

        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($rows, $from, $to) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['From', $from, 'To', $to]);
                fputcsv($out, ['Account', 'Type', 'Currency', 'Opening balance', 'Inflows', 'Outflows', 'Closing balance', 'Ledger mapping']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        ReportCenterController::csvSafe($row['account']->name),
                        $row['account']->type,
                        ReportCenterController::csvSafe($row['currency']?->code),
                        $row['opening'],$row['inflows'],$row['outflows'],
                        $row['closing'],$row['mapped']?'Mapped':'Missing',
                    ]);
                }
                fclose($out);
            }, 'cash-movements-' . $from . '-to-' . $to . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('finance.reports.cash-movements', [
            'rows' => array_values($rows), 'from' => $from, 'to' => $to,
            'currencyId' => $filters['currency_id'] ?? null, 'type' => $filters['type'] ?? null,
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
        ]);
    }
}
