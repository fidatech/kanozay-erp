<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\LedgerAccount;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TrialBalanceController extends Controller
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

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->where('la.is_group', false)
            ->when($filters['currency_id'] ?? null, fn ($q, $id) => $q->where('je.currency_id', $id))
            ->select('la.id as account_id', 'la.code', 'la.name', 'la.type', 'je.currency_id', 'jl.debit', 'jl.credit')
            ->orderBy('la.code');

        // Aggregate decimal strings in PHP so SQLite/MySQL never coerce money to binary floats.
        $balances = [];
        foreach ($query->get() as $line) {
            $key = $line->account_id . ':' . $line->currency_id;
            $balances[$key] ??= [
                'account_id' => (int) $line->account_id,
                'code' => $line->code,
                'name' => $line->name,
                'type' => $line->type,
                'currency_id' => (int) $line->currency_id,
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::zero(),
            ];
            $balances[$key]['debit'] = $balances[$key]['debit']->plus((string) $line->debit);
            $balances[$key]['credit'] = $balances[$key]['credit']->plus((string) $line->credit);
        }

        $currencyIds = collect($balances)->pluck('currency_id')->unique()->values();
        $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
        $rows = [];
        $totals = [];
        foreach ($balances as $balance) {
            $net = $balance['debit']->minus($balance['credit']);
            if ($net->compareTo(BigDecimal::zero()) === 0) {
                continue;
            }
            $debitBalance = $net->compareTo(BigDecimal::zero()) > 0 ? $net : BigDecimal::zero();
            $creditBalance = $net->compareTo(BigDecimal::zero()) < 0 ? $net->abs() : BigDecimal::zero();
            $balance['debit_balance'] = (string) $debitBalance;
            $balance['credit_balance'] = (string) $creditBalance;
            $balance['currency'] = $currencies->get($balance['currency_id']);
            $rows[] = $balance;
            $totals[$balance['currency_id']] ??= ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero()];
            $totals[$balance['currency_id']]['debit'] = $totals[$balance['currency_id']]['debit']->plus($debitBalance);
            $totals[$balance['currency_id']]['credit'] = $totals[$balance['currency_id']]['credit']->plus($creditBalance);
        }

        if (($filters['export'] ?? null) === 'csv') {
            $filename = 'trial-balance-' . $asOf . '.csv';
            return response()->streamDownload(function () use ($rows, $asOf) {
                $out = fopen('php://output', 'w');
                // UTF-8 BOM improves spreadsheet compatibility; values remain unconverted text.
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['As of', 'Account code', 'Account name', 'Account type', 'Currency', 'Debit balance', 'Credit balance']);
                foreach ($rows as $row) {
                    fputcsv($out, [$asOf, $row['code'], $row['name'], $row['type'], $row['currency']?->code, $row['debit_balance'], $row['credit_balance']]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('finance.reports.trial-balance', [
            'rows' => $rows,
            'totals' => $totals,
            'currencies' => $currencies,
            'currency' => isset($filters['currency_id']) ? Currency::find((int) $filters['currency_id']) : null,
            'currenciesForFilter' => Currency::where('is_active', true)->orderBy('code')->get(),
            'asOf' => $asOf,
        ]);
    }
}
