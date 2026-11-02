<?php
namespace App\Http\Controllers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Models\MoneyTransaction;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationCaseEvent;
use App\Models\User;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class TradeReconciliationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'reports.view']), 403);
        $filters = $request->validate(['type' => ['nullable', Rule::in(['receivable', 'payable'])], 'party_id' => ['nullable', 'integer', 'min:1'], 'currency_id' => ['nullable', 'integer', 'min:1']]);
        $type = $filters['type'] ?? 'receivable';
        $partyType = $type === 'receivable' ? 'customer' : 'supplier';
        $moneyType = $type === 'receivable' ? 'receipt' : 'payment';
        $parties = TradeParty::where('type', $partyType)->orderBy('name')->get();
        $documents = TradeDocument::where('type', $type)->with(['allocations','credits'])->get();
        $transactions = MoneyTransaction::where('type', $moneyType)->with('allocations')->get();
        $ledgers = \Illuminate\Support\Facades\DB::table('entity_ledger_accounts')
            ->where('entity_type', $partyType)->where('purpose', $type)->get();
        $ledgerToParty = $ledgers->keyBy(fn ($item) => $item->ledger_account_id . ':' . $item->currency_id);
        $partyNames = $parties->keyBy('id');
        $rows = [];
        foreach ($documents as $document) {
            $key = $document->trade_party_id . ':' . $document->currency_id;
            $rows[$key] ??= ['party' => $partyNames->get($document->trade_party_id)?->name ?? 'Unknown',
                'currency' => $document->currency_id, 'outstanding' => BigDecimal::zero(), 'unapplied' => BigDecimal::zero(), 'issues' => 0];
            $allocated = $this->allocationTotal($document);
            $credited = BigDecimal::zero();
            foreach ($document->credits as $credit) $credited = $credited->plus((string) $credit->amount);
            $amount = $this->money($document->amount);
            $remaining = $amount->minus($allocated)->minus($credited);
            $rows[$key]['outstanding'] = $rows[$key]['outstanding']->plus($remaining->compareTo(BigDecimal::zero()) > 0 ? $remaining : BigDecimal::zero());
            if ($allocated->compareTo($amount) > 0) $rows[$key]['issues']++;
        }
        $unmappedTransactions = [];
        foreach ($transactions as $transaction) {
            $mapping = $ledgerToParty->get($transaction->counterpart_ledger_account_id . ':' . $transaction->currency_id);
            if (!$mapping) {
                $unmappedTransactions[] = [
                    'id' => $transaction->id,
                    'currency' => $transaction->currency_id,
                    'amount' => (string) $this->positiveRemainder($transaction->amount, $this->allocationTotal($transaction)),
                ];
                continue;
            }
            $key = $mapping->entity_id . ':' . $transaction->currency_id;
            $rows[$key] ??= ['party' => $partyNames->get($mapping->entity_id)?->name ?? 'Unknown',
                'currency' => $transaction->currency_id, 'outstanding' => BigDecimal::zero(), 'unapplied' => BigDecimal::zero(), 'issues' => 0];
            $allocated = $this->allocationTotal($transaction);
            $amount = $this->money($transaction->amount);
            $rows[$key]['unapplied'] = $rows[$key]['unapplied']->plus($this->positiveRemainder($amount, $allocated));
            if ($allocated->compareTo($amount) > 0) $rows[$key]['issues']++;
        }
        $ledgerLines = \Illuminate\Support\Facades\DB::table('entity_ledger_accounts as ea')
            ->join('journal_lines as jl', 'jl.ledger_account_id', '=', 'ea.ledger_account_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.id', '=', 'jl.journal_entry_id')
                    ->on('je.currency_id', '=', 'ea.currency_id');
            })
            ->where('ea.entity_type', $partyType)->where('ea.purpose', $type)
            ->where('je.status', 'posted')
            ->select('ea.entity_id', 'ea.currency_id', 'jl.debit', 'jl.credit')->get();
        $ledgerBalances = [];
        foreach ($ledgerLines as $line) {
            $key = $line->entity_id . ':' . $line->currency_id;
            $ledgerBalances[$key] ??= ['debits' => BigDecimal::zero(), 'credits' => BigDecimal::zero()];
            $ledgerBalances[$key]['debits'] = $ledgerBalances[$key]['debits']->plus((string) $line->debit);
            $ledgerBalances[$key]['credits'] = $ledgerBalances[$key]['credits']->plus((string) $line->credit);
        }
        foreach ($rows as $key => &$row) {
            $ledger = $ledgerBalances[$key] ?? null;
            $row['ledger_account_id'] = $ledgers->first(fn ($item) => $item->entity_id . ':' . $item->currency_id === (string) $key)?->ledger_account_id;
            $row['ledger_balance'] = $ledger ? ($type === 'receivable'
                ? $ledger['debits']->minus($ledger['credits'])
                : $ledger['credits']->minus($ledger['debits'])) : null;
            $row['exposure'] = $row['outstanding']->minus($row['unapplied']);
            $row['difference'] = $row['ledger_balance'] === null ? null : $row['exposure']->minus($row['ledger_balance']);
            $row['difference_significant'] = $row['difference'] !== null && $row['difference']->abs()->compareTo(BigDecimal::of('0.0001')) > 0;
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
                $documentBreakdown->each(function ($doc) { $allocated=$this->allocationTotal($doc);$credited=BigDecimal::zero();foreach($doc->credits as $credit)$credited=$credited->plus((string)$credit->amount);$remaining=$this->money($doc->amount)->minus($allocated)->minus($credited);$doc->setAttribute('reconciliation_allocated',(string)$allocated);$doc->setAttribute('reconciliation_remaining',(string)($remaining->compareTo(BigDecimal::zero())>0?$remaining:BigDecimal::zero())); });
                $paymentBreakdown->each(function ($tx) { $allocated=$this->allocationTotal($tx);$tx->setAttribute('reconciliation_allocated',(string)$allocated);$tx->setAttribute('reconciliation_remaining',(string)$this->positiveRemainder($tx->amount,$allocated)); });
                $selectedRow = $rows[$key];
                $reconciliationSummary = [
                    'outstanding' => (string) $selectedRow['outstanding'],
                    'unapplied' => (string) $selectedRow['unapplied'],
                    'exposure' => (string) $selectedRow['exposure'],
                    'ledger' => $selectedRow['ledger_balance'] === null ? null : (string) $selectedRow['ledger_balance'],
                    'difference' => $selectedRow['difference'] === null ? null : (string) $selectedRow['difference'],
                    'difference_significant' => $selectedRow['difference_significant'],
                    'issues' => $selectedRow['issues'],
                    'status' => !$mappedLedger ? 'missing_mapping' : ($selectedRow['ledger_balance'] === null ? 'no_postings' : ($selectedRow['issues'] > 0 ? 'allocation_exception' : ($selectedRow['difference_significant'] ? 'difference' : 'matched'))),
                ];
                if ($mappedLedger) {
                    $journalBreakdown = \Illuminate\Support\Facades\DB::table('journal_lines as jl')
                        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                        ->where('jl.ledger_account_id', $mappedLedger)
                        ->where('je.currency_id', (int) $filters['currency_id'])
                        ->where('je.status', 'posted')
                        ->select('je.number', 'je.entry_date', 'je.description', 'jl.memo', 'jl.debit', 'jl.credit')
                        ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('jl.id')->get();
                    $journalBreakdown->each(function ($line) {
                        $line->debit_nonzero = BigDecimal::of((string) $line->debit)->compareTo(BigDecimal::zero()) > 0;
                        $line->credit_nonzero = BigDecimal::of((string) $line->credit)->compareTo(BigDecimal::zero()) > 0;
                    });
                }
            }
        }
        $cases = ReconciliationCase::where('type', $type)->with('assignee')->get()->keyBy(fn ($case) => $case->trade_party_id . ':' . $case->currency_id);
        foreach ($cases as $key => $case) {
            if ($case->status !== 'resolved' || !$case->approved_at) continue;
            $row = $rows[$key] ?? null;
            $exposure = $row ? $row['exposure'] : null;
            $ledger = $row['ledger_balance'] ?? null;
            if ($exposure !== null && $ledger !== null && $case->approved_exposure !== null && $case->approved_ledger !== null
                && $exposure->minus((string) $case->approved_exposure)->abs()->compareTo(BigDecimal::of('0.0001')) <= 0
                && $ledger->minus((string) $case->approved_ledger)->abs()->compareTo(BigDecimal::of('0.0001')) <= 0) continue;
            // GET requests are read-only. Do not silently revoke approvals.
            $case->setAttribute('balance_changed_since_approval', true);
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
        abort_unless($request->user()->canAny(['administration.update', 'finance.update']), 403);
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
        return DB::transaction(function () use ($key, $data, $request) {
        $case = ReconciliationCase::where($key)->lockForUpdate()->first() ?? new ReconciliationCase($key);
        abort_if($case->exists && $case->status === 'resolved', 422, 'Reopen approved cases through the audited reopening action.');
        $oldStatus = $case->exists ? $case->status : null;
        if ($data['status'] === 'resolved') $data['status'] = 'investigating';
        $data['updated_by'] = $request->user()->id;
        $data['resolved_at'] = null;
        $data['approved_by'] = null;
        $data['approved_at'] = null;
        $data['approved_exposure'] = null;
        $data['approved_ledger'] = null;
        $case->fill($data)->save();
        ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'actor_id'=>$request->user()->id,
            'action'=>'updated','old_status'=>$oldStatus,'new_status'=>$case->status,'notes'=>$case->notes]);
        return redirect()->route('finance.reconciliation.index', ['type'=>$data['type'],'party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']])->with('success','Reconciliation case saved.');
        });
    }
    public function reopenCase(Request $request, ReconciliationCase $case)
    {
        abort_unless($request->user()->canAny(['administration.update', 'finance.update']), 403);
        $data = $request->validate(['reopen_reason'=>['required','string','min:10','max:5000']]);
        return DB::transaction(function () use ($request, $case, $data) {
            $case = ReconciliationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->status === 'resolved' && $case->approved_at, 422);
            $partyType = $case->type === 'receivable' ? 'customer' : 'supplier';
            $ledgerId = DB::table('entity_ledger_accounts')->where('entity_type', $partyType)
                ->where('entity_id', $case->trade_party_id)->where('currency_id', $case->currency_id)
                ->where('purpose', $case->type)->value('ledger_account_id');
            $currentLedger = null;
            if ($ledgerId) $currentLedger = $this->postedLedgerBalance((int) $ledgerId, (int) $case->currency_id, $case->type);
            $ledgerChanged = $currentLedger === null || $case->approved_ledger === null
                || $currentLedger->minus((string) $case->approved_ledger)->abs()->compareTo(BigDecimal::of('0.0001')) > 0;
            $documents = TradeDocument::where('type', $case->type)->where('trade_party_id', $case->trade_party_id)
                ->where('currency_id', $case->currency_id)->with('allocations')->get();
            $exposure = $this->sumRemainders($documents);
            if ($ledgerId) {
                $moneyType = $case->type === 'receivable' ? 'receipt' : 'payment';
                $payments = MoneyTransaction::where('type', $moneyType)->where('counterpart_ledger_account_id', $ledgerId)
                    ->where('currency_id', $case->currency_id)->with('allocations')->get();
                $exposure = $exposure->minus($this->sumRemainders($payments));
            }
            $exposureChanged = $case->approved_exposure === null
                || $exposure->minus((string) $case->approved_exposure)->abs()->compareTo(BigDecimal::of('0.0001')) > 0;
            abort_unless($ledgerChanged || $exposureChanged, 422, 'Approved balances have not changed.');
            $case->update(['status'=>'investigating','resolved_at'=>null,'approved_at'=>null,
                'approved_by'=>null,'approved_exposure'=>null,'approved_ledger'=>null,
                'updated_by'=>$request->user()->id]);
            ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'actor_id'=>$request->user()->id,
                'action'=>'reopened','old_status'=>'resolved','new_status'=>'investigating',
                'notes'=>$data['reopen_reason']]);
            return redirect()->route('finance.reconciliation.index', ['type'=>$case->type,
                'party_id'=>$case->trade_party_id,'currency_id'=>$case->currency_id]);
        });
    }

    public function approveCase(Request $request, ReconciliationCase $case)
    {
        abort_unless($request->user()->canAny(['administration.update', 'finance.update']),403);
        return DB::transaction(function () use ($request, $case) {
        $case = ReconciliationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
        abort_if((int)$case->updated_by === (int)$request->user()->id,403,'Independent approval required.');
        abort_unless($case->status === 'investigating',422);
        $review = $request->validate(['resolution_notes'=>['required','string','min:10','max:5000']]);
        $partyType = $case->type === 'receivable' ? 'customer' : 'supplier';
        abort_unless(TradeParty::whereKey($case->trade_party_id)->where('type',$partyType)->exists(),422);
        $documents = TradeDocument::where('type',$case->type)->where('trade_party_id',$case->trade_party_id)->where('currency_id',$case->currency_id)->with('allocations')->get();
        $exposure = $this->sumRemainders($documents);
        abort_if($documents->contains(fn($doc)=>$this->allocationTotal($doc)->compareTo($this->money($doc->amount))>0),422,'Excess document allocation must be corrected.');
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
        $payments = MoneyTransaction::where('type',$moneyType)->where('counterpart_ledger_account_id',$ledgerId)->where('currency_id',$case->currency_id)->with('allocations')->get();
        abort_if($payments->contains(fn($tx)=>$this->allocationTotal($tx)->compareTo($this->money($tx->amount))>0),422,'Excess payment allocation must be corrected.');
        $exposure = $exposure->minus($this->sumRemainders($payments));
        $balance = $this->postedLedgerBalance((int) $ledgerId, (int) $case->currency_id, $case->type);
        abort_unless($balance !== null,422,'No posted ledger lines.');
        $case->update(['status'=>'resolved','approved_by'=>$request->user()->id,'approved_at'=>now(),
            'resolved_at'=>now(),'approved_exposure'=>(string)$exposure,'approved_ledger'=>(string)$balance]);
        ReconciliationCaseEvent::create(['reconciliation_case_id'=>$case->id,'actor_id'=>$request->user()->id,
            'action'=>'approved','old_status'=>'investigating','new_status'=>'resolved','notes'=>$review['resolution_notes']]);
        return redirect()->route('finance.reconciliation.index',['type'=>$case->type,'party_id'=>$case->trade_party_id,'currency_id'=>$case->currency_id]);
        });
    }

    private function positiveRemainder(mixed $amount, mixed $allocated): BigDecimal
    {
        $remainder = $this->money($amount)->minus($allocated instanceof BigDecimal ? $allocated : $this->money($allocated ?? '0'));
        return $remainder->compareTo(BigDecimal::zero()) > 0 ? $remainder : BigDecimal::zero();
    }

    private function money(mixed $amount): BigDecimal
    {
        return BigDecimal::of((string) ($amount ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }

    private function allocationTotal($item): BigDecimal
    {
        $total = BigDecimal::zero();
        $allocations = $item->relationLoaded('allocations') ? $item->allocations : $item->allocations()->get(['amount']);
        foreach ($allocations as $allocation) $total = $total->plus($this->money($allocation->amount));
        return $total;
    }

    private function sumRemainders($items): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($items as $item) $total = $total->plus($this->positiveRemainder($item->amount, $this->allocationTotal($item)));
        return $total;
    }

    private function postedLedgerBalance(int $ledgerId, int $currencyId, string $type): ?BigDecimal
    {
        $lines = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.ledger_account_id', $ledgerId)->where('je.currency_id', $currencyId)->where('je.status', 'posted')
            ->select('jl.debit', 'jl.credit')->get();
        if ($lines->isEmpty()) return null;
        $debits = BigDecimal::zero(); $credits = BigDecimal::zero();
        foreach ($lines as $line) { $debits = $debits->plus((string) $line->debit); $credits = $credits->plus((string) $line->credit); }
        return $type === 'receivable' ? $debits->minus($credits) : $credits->minus($debits);
    }

}
