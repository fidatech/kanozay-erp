@php($remaining=max(0,(float)$doc->amount-(float)($doc->allocations_sum_amount??0)))
@if($remaining<=0)
<span style="color:#15803d;font-weight:700"><i class="fa-solid fa-circle-check"></i> Settled</span>
@else
@can('administration.update')
<form method="POST" action="{{ route('finance.settlements.store') }}" style="display:flex;gap:6px;align-items:center;min-width:280px;flex-wrap:wrap">
@csrf
<input type="hidden" name="trade_document_id" value="{{ $doc->id }}">
<select name="money_transaction_id" required aria-label="Payment to allocate" style="min-width:145px;max-width:250px">
<option value="">Select {{ $type==='receivable'?'receipt':'payment' }}</option>
@foreach($settlementTransactions as $payment)
@php($mapping=$settlementLedgerMap->get($doc->trade_party_id.':'.$doc->currency_id))
@if($mapping && $payment->currency_id===$doc->currency_id && (int)$payment->counterpart_ledger_account_id===(int)$mapping->ledger_account_id)
<option value="{{ $payment->id }}">#{{ $payment->id }} · {{ number_format((float)$payment->amount-(float)($payment->allocations_sum_amount??0),2) }} available</option>
@endif
@endforeach
</select>
<input type="number" name="amount" min="0.0001" step="0.0001" max="{{ $remaining }}" placeholder="Amount" aria-label="Amount to allocate" required style="width:100px">
<button class="btn" type="submit">Allocate</button>
</form>
@endcan
@endif
