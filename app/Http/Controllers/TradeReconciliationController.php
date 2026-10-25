<?php
namespace App\Http\Controllers;

use App\Models\MoneyTransaction;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradeReconciliationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('administration.view'), 403);
        $filters = $request->validate(['type' => ['nullable', Rule::in(['receivable', 'payable'])], 'party_id' => ['nullable', 'integer', 'min:1'], 'currency_id' => ['nullable', 'integer', 'min:1']]);
        $type = $filters['type'] ?? 'receivable';
        $partyType = $type === 'receivable' ? 'customer' : 'supplier';
        $moneyType = $type === 'receivable' ? 'receipt' : 'payment';
        $parties = TradeParty::where('type', $partyType)->orderBy('name')->get();
        $documents = TradeDocument::where('type', $type)->withSum('allocations', 'amount')->get();
        $transactions = MoneyTransaction::where('type', $moneyType)->withSum('allocations', 'amount')->get();
        $ledgers = \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')
            ->where('entity_type', $partyType)->where('purpose', $type)->get();
        $ledgerToParty = $ledgers->keyBy(fn ($item) => $item->ledger_account_id . ':' . $item->currency_id);
        $partyNames = $parties->keyBy('id');
        $rows = [];
        foreach ($documents as $document) {
            $key = $document->trade_party_id . ':' . $document->currency_id;
            $rows[$key] ??= ['party' => $partyNames->get($document->trade_party_id)?->name ?? 'Unknown',
                'currency' => $document->currency_id, 'outstanding' => 0, 'unapplied' => 0, 'issues' => 0];
            $allocated = (float) ($document->allocations_sum_amount ?? 0);
            $rows[$key]['outstanding'] += max(0, (float) $document->amount - $allocated);
            if ($allocated > (float) $document->amount + 0.00001) $rows[$key]['issues']++;
        }
        $unmappedTransactions = [];
        foreach ($transactions as $transaction) {
            $mapping = $ledgerToParty->get($transaction->counterpart_ledger_account_id . ':' . $transaction->currency_id);
            if (!$mapping) {
                $unmappedTransactions[] = [
                    'id' => $transaction->id,
                    'currency' => $transaction->currency_id,
                    'amount' => max(0, (float) $transaction->amount - (float) ($transaction->allocations_sum_amount ?? 0)),
                ];
                continue;
            }
            $key = $mapping->entity_id . ':' . $transaction->currency_id;
            $rows[$key] ??= ['party' => $partyNames->get($mapping->entity_id)?->name ?? 'Unknown',
                'currency' => $transaction->currency_id, 'outstanding' => 0, 'unapplied' => 0, 'issues' => 0];
            $allocated = (float) ($transaction->allocations_sum_amount ?? 0);
            $rows[$key]['unapplied'] += max(0, (float) $transaction->amount - $allocated);
            if ($allocated > (float) $transaction->amount + 0.00001) $rows[$key]['issues']++;
        }
        $ledgerBalances = \Illuminate\Support\Facades\DB::table('entity_ledger_accounts as ea')
            ->join('journal_lines as jl', 'jl.ledger_account_id', '=', 'ea.ledger_account_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.id', '=', 'jl.journal_entry_id')
                    ->on('je.currency_id', '=', 'ea.currency_id');
            })
            ->where('ea.entity_type', $partyType)->where('ea.purpose', $type)
            ->where('je.status', 'posted')
            ->selectRaw('ea.entity_id, ea.currency_id, SUM(jl.debit) AS debits, SUM(jl.credit) AS credits')
            ->groupBy('ea.entity_id', 'ea.currency_id')->get()
            ->keyBy(fn ($item) => $item->entity_id . ':' . $item->currency_id);
        foreach ($rows as $key => &$row) {
            $ledger = $ledgerBalances->get($key);
            $row['ledger_account_id'] = $ledgers->first(fn ($item) => $item->entity_id . ':' . $item->currency_id === (string) $key)?->ledger_account_id;
            $row['ledger_balance'] = $ledger ? ($type === 'receivable'
                ? (float) $ledger->debits - (float) $ledger->credits
                : (float) $ledger->credits - (float) $ledger->debits) : null;
            $row['difference'] = $row['ledger_balance'] === null ? null
                : round($row['outstanding'] - $row['unapplied'] - $row['ledger_balance'], 4);
        }
        unset($row);
        $selectedParty = null;
        $documentBreakdown = collect();
        $paymentBreakdown = collect();
        $journalBreakdown = collect();
        $reconciliationSummary = null;
        if (isset($filters['party_id'], $filters['currency_id'])) {
            $party = $parties->firstWhere('id', (int) $filters['party_id']);
            $key = $filters['party_id'] . ':' . $filters['currency_id'];
            if ($party && isset($rows[$key])) {
                $selectedParty = $party;
                $documentBreakdown = $documents->filter(fn ($doc) => (int) $doc->trade_party_id === (int) $party->id && (int) $doc->currency_id === (int) $filters['currency_id'])->values();
                $mappedLedger = $rows[$key]['ledger_account_id'];
                $paymentBreakdown = $transactions->filter(fn ($tx) => $mappedLedger && (int) $tx->counterpart_ledger_account_id === (int) $mappedLedger && (int) $tx->currency_id === (int) $filters['currency_id'])->values();
                $selectedRow = $rows[$key];
                $reconciliationSummary = [
                    'outstanding' => round($selectedRow['outstanding'], 4),
                    'unapplied' => round($selectedRow['unapplied'], 4),
                    'exposure' => round($selectedRow['outstanding'] - $selectedRow['unapplied'], 4),
                    'ledger' => $selectedRow['ledger_balance'],
                    'difference' => $selectedRow['difference'],
                    'issues' => $selectedRow['issues'],
                    'status' => !$mappedLedger ? 'missing_mapping' : ($selectedRow['ledger_balance'] === null ? 'no_postings' : ($selectedRow['issues'] > 0 ? 'allocation_exception' : (abs($selectedRow['difference']) > 0.0001 ? 'difference' : 'matched'))),
                ];
                if ($mappedLedger) {
                    $journalBreakdown = \Illuminate\Support\Facades\DB::table('journal_lines as jl')
                        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                        ->where('jl.ledger_account_id', $mappedLedger)
                        ->where('je.currency_id', (int) $filters['currency_id'])
                        ->where('je.status', 'posted')
                        ->select('je.number', 'je.entry_date', 'je.description', 'jl.memo', 'jl.debit', 'jl.credit')
                        ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('jl.id')->get();
                }
            }
        }
        $cases = ReconciliationCase::where('type', $type)->with('assignee')->get()->keyBy(fn ($case) => $case->trade_party_id . ':' . $case->currency_id);
        $partiesForCases = $parties->pluck('name', 'id');
        $assignees = User::orderBy('name')->get(['id','name']);
        $currencies = \App\Models\Currency::pluck('code', 'id');
        return view('finance.documents.reconciliation', compact('type', 'rows', 'currencies', 'unmappedTransactions', 'selectedParty', 'documentBreakdown', 'paymentBreakdown', 'journalBreakdown', 'reconciliationSummary', 'filters', 'cases', 'assignees', 'partiesForCases'));
    }
    public function saveCase(Request $request)
    {
        abort_unless($request->user()->can('administration.update'), 403);
        $data = $request->validate([
            'type'=>['required',Rule::in(['receivable','payable'])],
            'trade_party_id'=>['required','integer','exists:trade_parties,id'],
            'currency_id'=>['required','integer','exists:currencies,id'],
            'status'=>['required',Rule::in(['open','investigating','resolved'])],
            'assigned_to'=>['nullable','integer','exists:users,id'],
            'notes'=>['nullable','string','max:5000'],
        ]);
        $partyType = $data['type'] === 'receivable' ? 'customer' : 'supplier';
        abort_unless(TradeParty::whereKey($data['trade_party_id'])->where('type',$partyType)->exists(),422);
        $data['updated_by'] = $request->user()->id;
        $data['resolved_at'] = $data['status'] === 'resolved' ? now() : null;
        ReconciliationCase::updateOrCreate(
            ['type'=>$data['type'],'trade_party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']],$data
        );
        return redirect()->route('finance.reconciliation.index', ['type'=>$data['type'],'party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']])->with('success','Reconciliation case saved.');
    }

}
