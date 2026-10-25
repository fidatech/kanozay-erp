<?php
namespace App\Http\Controllers;

use App\Models\MoneyTransaction;
use App\Models\TradeDocument;
use App\Models\TradeParty;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TradeReconciliationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('administration.view'), 403);
        $type = $request->validate(['type' => ['nullable', Rule::in(['receivable', 'payable'])]])['type'] ?? 'receivable';
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
        foreach ($transactions as $transaction) {
            $mapping = $ledgerToParty->get($transaction->counterpart_ledger_account_id . ':' . $transaction->currency_id);
            if (!$mapping) continue;
            $key = $mapping->entity_id . ':' . $transaction->currency_id;
            $rows[$key] ??= ['party' => $partyNames->get($mapping->entity_id)?->name ?? 'Unknown',
                'currency' => $transaction->currency_id, 'outstanding' => 0, 'unapplied' => 0, 'issues' => 0];
            $allocated = (float) ($transaction->allocations_sum_amount ?? 0);
            $rows[$key]['unapplied'] += max(0, (float) $transaction->amount - $allocated);
            if ($allocated > (float) $transaction->amount + 0.00001) $rows[$key]['issues']++;
        }
        $currencies = \App\Models\Currency::pluck('code', 'id');
        return view('finance.documents.reconciliation', compact('type', 'rows', 'currencies'));
    }
}
