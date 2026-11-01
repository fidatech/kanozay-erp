<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfitAndLossController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'reports.view']), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->whereIn('la.type', ['income', 'expense'])
            ->where('la.is_group', false)
            ->when($filters['currency_id'] ?? null, fn ($q, $id) => $q->where('je.currency_id', $id))
            ->select('la.id as account_id', 'la.code', 'la.name', 'la.type', 'je.currency_id', 'jl.debit', 'jl.credit')
            ->orderBy('la.code')->get();

        // Preserve the database decimal strings; do not use SQL SUM/float for money totals.
        $accountTotals = [];
        foreach ($lines as $line) {
            $key = $line->account_id . ':' . $line->currency_id;
            $accountTotals[$key] ??= [
                'account_id' => (int) $line->account_id, 'code' => $line->code,
                'name' => $line->name, 'type' => $line->type,
                'currency_id' => (int) $line->currency_id,
                'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(),
            ];
            $accountTotals[$key]['debit'] = $accountTotals[$key]['debit']->plus((string) $line->debit);
            $accountTotals[$key]['credit'] = $accountTotals[$key]['credit']->plus((string) $line->credit);
        }

        $ids = collect($accountTotals)->pluck('currency_id')->unique()->values();
        $currencies = Currency::whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        $totals = [];
        foreach ($accountTotals as $account) {
            $amount = $account['type'] === 'income'
                ? $account['credit']->minus($account['debit'])
                : $account['debit']->minus($account['credit']);
            if ($amount->compareTo(BigDecimal::zero()) === 0) {
                continue;
            }
            $account['amount'] = (string) $amount;
            $account['currency'] = $currencies->get($account['currency_id']);
            $rows[] = $account;
            $totals[$account['currency_id']] ??= ['income' => BigDecimal::zero(), 'expense' => BigDecimal::zero()];
            $totals[$account['currency_id']][$account['type']] = $totals[$account['currency_id']][$account['type']]->plus($amount);
        }
        foreach ($totals as &$total) {
            $total['profit'] = $total['income']->minus($total['expense']);
        }
        unset($total);

        $selectedCurrency = isset($filters['currency_id']) ? Currency::find((int) $filters['currency_id']) : null;
        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($rows, $totals, $currencies, $from, $to) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['From', $from, 'To', $to]);
                fputcsv($out, ['Account code', 'Account name', 'Category', 'Currency', 'Amount']);
                foreach ($rows as $row) {
                    fputcsv($out, [$row['code'], $row['name'], $row['type'], $row['currency']?->code, $row['amount']]);
                }
                foreach ($totals as $currencyId => $total) {
                    $code = $currencies->get($currencyId)?->code;
                    fputcsv($out, ['', 'Total income', 'income', $code, (string) $total['income']]);
                    fputcsv($out, ['', 'Total expenses', 'expense', $code, (string) $total['expense']]);
                    fputcsv($out, ['', 'Net profit / (loss)', 'profit', $code, (string) $total['profit']]);
                }
                fclose($out);
            }, 'profit-and-loss-' . $from . '-to-' . $to . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('finance.reports.profit-and-loss', [
            'rows' => $rows, 'totals' => $totals, 'currencies' => $currencies,
            'currenciesForFilter' => Currency::where('is_active', true)->orderBy('code')->get(),
            'currency' => $selectedCurrency, 'from' => $from, 'to' => $to,
        ]);
    }
}
