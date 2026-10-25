<?php
namespace App\Http\Controllers;

use App\Models\MoneyTransaction;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationCaseEvent;
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
        foreach ($cases as $key => $case) {
            if ($case->status !== 'resolved' || !$case->approved_at) continue;
            $row = $rows[$key] ?? null;
            $exposure = $row ? round($row['outstanding'] - $row['unapplied'], 4) : null;
            $ledger = $row['ledger_balance'] ?? null;
            if ($exposure !== null && $ledger !== null && $case->approved_exposure !== null && $case->approved_ledger !== null
                && abs($exposure - (float) $case->approved_exposure) <= 0.0001
                && abs($ledger - (float) $case->approved_ledger) <= 0.0001) continue;
            $case->update(['status'=>'investigating','resolved_at'=>null,'approved_at'=>null,'approved_by'=>null]);
            ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'action'=>'auto_reopened','old_status'=>'resolved','new_status'=>'investigating','notes'=>'Balances changed or the posted ledger is unavailable.']);
        }
        $selectedCaseEvents = isset($filters['party_id'], $filters['currency_id'])
            ? ($cases->get($filters['party_id'].':'.$filters['currency_id'])?->events()->with('actor')->limit(30)->get() ?? collect())
            : collect();
        $partiesForCases = $parties->pluck('name', 'id');
        $assignees = User::orderBy('name')->get(['id','name']);
        $currencies = \App\Models\Currency::pluck('code', 'id');
        return view('finance.documents.reconciliation', compact('type', 'rows', 'currencies', 'unmappedTransactions', 'selectedParty', 'documentBreakdown', 'paymentBreakdown', 'journalBreakdown', 'reconciliationSummary', 'filters', 'cases', 'assignees', 'partiesForCases', 'selectedCaseEvents'));
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
        $key = ['type'=>$data['type'],'trade_party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']];
        $case = ReconciliationCase::firstOrNew($key);
        $oldStatus = $case->exists ? $case->status : null;
        if ($data['status'] === 'resolved') $data['status'] = 'investigating';
        $data['updated_by'] = $request->user()->id;
        $data['resolved_at'] = null;
        $data['approved_by'] = null;
        $data['approved_at'] = null;
        $case->fill($data)->save();
        ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'actor_id'=>$request->user()->id,
            'action'=>'updated','old_status'=>$oldStatus,'new_status'=>$case->status,'notes'=>$case->notes]);
        return redirect()->route('finance.reconciliation.index', ['type'=>$data['type'],'party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']])->with('success','Reconciliation case saved.');
    }
    public function approveCase(Request $request, ReconciliationCase $case)
    {
        abort_unless($request->user()->can('administration.update'),403);
        abort_if((int)$case->updated_by === (int)$request->user()->id,403,'Independent approval required.');
        abort_unless($case->status === 'investigating',422);
        $review = $request->validate(['resolution_notes'=>['required','string','min:10','max:5000']]);
        $partyType = $case->type === 'receivable' ? 'customer' : 'supplier';
        abort_unless(TradeParty::whereKey($case->trade_party_id)->where('type',$partyType)->exists(),422);
        $documents = TradeDocument::where('type',$case->type)->where('trade_party_id',$case->trade_party_id)->where('currency_id',$case->currency_id)->withSum('allocations','amount')->get();
        $exposure = $documents->sum(fn($doc)=>max(0,(float)$doc->amount-(float)($doc->allocations_sum_amount??0)));
        abort_if($documents->contains(fn($doc)=>(float)($doc->allocations_sum_amount??0) > (float)$doc->amount + 0.00001),422,'Excess document allocation must be corrected.');
        $ledgerId = \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')->where('entity_type',$partyType)->where('entity_id',$case->trade_party_id)->where('currency_id',$case->currency_id)->where('purpose',$case->type)->value('ledger_account_id');
        abort_unless($ledgerId,422,'Missing ledger mapping.');
        $moneyType = $case->type === 'receivable' ? 'receipt' : 'payment';
        // Only transactions linked by allocation to this party can be attributed
        // to this case. Unassigned receipts remain visible in the exception register.
        $unmapped = MoneyTransaction::where('type',$moneyType)
            ->where('currency_id',$case->currency_id)
            ->whereHas('allocations.document', fn ($query) => $query
                ->where('trade_party_id',$case->trade_party_id)
                ->where('currency_id',$case->currency_id))
            ->where('counterpart_ledger_account_id','!=',$ledgerId)->exists();
        $missingMapping = MoneyTransaction::where('type',$moneyType)
            ->where('currency_id',$case->currency_id)
            ->whereHas('allocations.document', fn ($query) => $query
                ->where('trade_party_id',$case->trade_party_id)
                ->where('currency_id',$case->currency_id))
            ->whereNull('counterpart_ledger_account_id')->exists();
        abort_if($unmapped || $missingMapping,422,'Settlement allocated to this party has a missing or conflicting ledger mapping.');
        $payments = MoneyTransaction::where('type',$moneyType)->where('counterpart_ledger_account_id',$ledgerId)->where('currency_id',$case->currency_id)->withSum('allocations','amount')->get();
        abort_if($payments->contains(fn($tx)=>(float)($tx->allocations_sum_amount??0) > (float)$tx->amount + 0.00001),422,'Excess payment allocation must be corrected.');
        $exposure -= $payments->sum(fn($tx)=>max(0,(float)$tx->amount-(float)($tx->allocations_sum_amount??0)));
        $ledger = \Illuminate\Support\Facades\DB::table('journal_lines as jl')->join('journal_entries as je','je.id','=','jl.journal_entry_id')
            ->where('jl.ledger_account_id',$ledgerId)->where('je.currency_id',$case->currency_id)->where('je.status','posted')
            ->selectRaw('SUM(jl.debit) as debits, SUM(jl.credit) as credits, COUNT(*) as lines')->first();
        abort_unless($ledger && $ledger->lines,422,'No posted ledger lines.');
        $balance = $case->type === 'receivable' ? (float)$ledger->debits-(float)$ledger->credits : (float)$ledger->credits-(float)$ledger->debits;
        $case->update(['status'=>'resolved','approved_by'=>$request->user()->id,'approved_at'=>now(),
            'resolved_at'=>now(),'approved_exposure'=>round($exposure,4),'approved_ledger'=>round($balance,4)]);
        ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'actor_id'=>$request->user()->id,
            'action'=>'approved','old_status'=>'investigating','new_status'=>'resolved','notes'=>$review['resolution_notes']]);
        return redirect()->route('finance.reconciliation.index',['type'=>$case->type,'party_id'=>$case->trade_party_id,'currency_id'=>$case->currency_id]);
    }

}
