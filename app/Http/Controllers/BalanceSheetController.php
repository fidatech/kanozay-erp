<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\FinancialSetting;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BalanceSheetController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'reports.view']), 403);
        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
        $asOf = $filters['as_of'] ?? now()->toDateString();
        $fiscalYearStart = $this->fiscalYearStart($asOf, FinancialSetting::query()->value('fiscal_year_start') ?? '01-01');

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('la.type', ['asset', 'liability', 'equity', 'income', 'expense'])
            ->where('la.is_group', false)
            ->when($filters['currency_id'] ?? null, fn ($q, $id) => $q->where('je.currency_id', $id))
            ->select('la.id as account_id', 'la.code', 'la.name', 'la.type', 'je.currency_id', 'je.entry_date', 'jl.debit', 'jl.credit')
            ->orderBy('la.code')->get();

        // Account balances and current fiscal-year earnings remain exact decimals per currency.
        $accounts = [];
        foreach ($lines as $line) {
            if (in_array($line->type, ['income', 'expense'], true) && $line->entry_date < $fiscalYearStart) {
                continue;
            }
            $key = $line->account_id . ':' . $line->currency_id;
            $accounts[$key] ??= [
                'account_id' => (int) $line->account_id, 'code' => $line->code,
                'name' => $line->name, 'type' => $line->type,
                'currency_id' => (int) $line->currency_id,
                'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(),
            ];
            $accounts[$key]['debit'] = $accounts[$key]['debit']->plus((string) $line->debit);
            $accounts[$key]['credit'] = $accounts[$key]['credit']->plus((string) $line->credit);
        }

        $ids = collect($accounts)->pluck('currency_id')->unique()->values();
        $currencies = Currency::whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        $totals = [];
        foreach ($accounts as $account) {
            $type = $account['type'];
            $amount = in_array($type, ['asset', 'expense'], true)
                ? $account['debit']->minus($account['credit'])
                : $account['credit']->minus($account['debit']);
            if ($amount->compareTo(BigDecimal::zero()) === 0) {
                continue;
            }
            $account['amount'] = (string) $amount;
            $account['currency'] = $currencies->get($account['currency_id']);
            $rows[] = $account;
            $totals[$account['currency_id']] ??= [
                'asset' => BigDecimal::zero(), 'liability' => BigDecimal::zero(),
                'equity' => BigDecimal::zero(), 'income' => BigDecimal::zero(),
                'expense' => BigDecimal::zero(),
            ];
            $totals[$account['currency_id']][$type] = $totals[$account['currency_id']][$type]->plus($amount);
        }
        foreach ($totals as &$total) {
            $total['current_earnings'] = $total['income']->minus($total['expense']);
            $total['liabilities_and_equity'] = $total['liability']->plus($total['equity'])->plus($total['current_earnings']);
            $total['difference'] = $total['asset']->minus($total['liabilities_and_equity']);
        }
        unset($total);

        $selectedCurrency = isset($filters['currency_id']) ? Currency::find((int) $filters['currency_id']) : null;
        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($rows, $totals, $currencies, $asOf, $fiscalYearStart) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['As of', $asOf, 'Fiscal-year start', $fiscalYearStart]);
                fputcsv($out, ['Account code', 'Account name', 'Section', 'Currency', 'Balance']);
                foreach ($rows as $row) {
                    if (in_array($row['type'], ['asset', 'liability', 'equity'], true)) {
                        fputcsv($out, [$row['code'], $row['name'], $row['type'], $row['currency']?->code, $row['amount']]);
                    }
                }
                foreach ($totals as $currencyId => $total) {
                    $code = $currencies->get($currencyId)?->code;
                    foreach (['asset', 'liability', 'equity', 'current_earnings', 'liabilities_and_equity', 'difference'] as $key) {
                        fputcsv($out, ['', ucwords(str_replace('_', ' ', $key)), $key, $code, (string) $total[$key]]);
                    }
                }
                fclose($out);
            }, 'balance-sheet-' . $asOf . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('finance.reports.balance-sheet', [
            'rows' => $rows, 'totals' => $totals, 'currencies' => $currencies,
            'currenciesForFilter' => Currency::where('is_active', true)->orderBy('code')->get(),
            'currency' => $selectedCurrency, 'asOf' => $asOf, 'fiscalYearStart' => $fiscalYearStart,
        ]);
    }

    private function fiscalYearStart(string $asOf, string $monthDay): string
    {
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        $date = CarbonImmutable::parse($asOf);
        $startYear = $date->format('m-d') >= $monthDay ? (int) $date->format('Y') : (int) $date->format('Y') - 1;
        $first = CarbonImmutable::create($startYear, $month, 1);
        $day = min($day, $first->daysInMonth);
        return $first->setDay($day)->toDateString();
    }
}
