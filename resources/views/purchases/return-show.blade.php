@extends('layouts.kanozay')
@section('content')
<style>
.return-sheet{max-width:930px;margin:auto;background:white;border:1px solid #d8e3f0;padding:30px;border-radius:10px}
.return-sheet table{width:100%;border-collapse:collapse;font-size:12px}
.return-sheet th,.return-sheet td{padding:12px;border-bottom:1px solid #dfe7ef}
.return-sheet th{background:#eaf3fc}
@media print{@page{size:A4;margin:12mm}.top,.rail,.sidebar,.heading,.no-print{display:none!important}.return-sheet{border:0;box-shadow:none;max-width:none;padding:0}}
</style>
<div class="heading"><div><h1>Purchase return {{ $return->number }}</h1><p>Supplier return record linked to original goods receipt</p></div><div class="actions"><a class="btn btn-light" href="{{ route('workspace.page',['purchases','purchase-returns']) }}">Back to returns</a><button class="btn" onclick="window.print()">Print / Save PDF</button></div></div>
<div class="return-sheet">
<header style="display:flex;gap:18px;align-items:center;border-bottom:3px solid #277746;padding-bottom:16px;margin-bottom:20px">
<img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay" style="max-width:130px;max-height:85px"><div><h2>Kanozay Foods Manufacturing Company</h2><p>Supplier Purchase Return Note — {{ $return->number }}</p></div>
</header>
<p><strong>Supplier:</strong> {{ $return->order?->supplier?->name }} · <strong>Purchase order:</strong> {{ $return->order?->number }} · <strong>Receipt:</strong> {{ $return->receipt?->number }}</p>
<p><strong>Date:</strong> {{ $return->returned_on?->toDateString() }} · <strong>Supplier reference:</strong> {{ $return->supplier_reference??'—' }}</p>
<p><strong>Reason:</strong> {{ $return->reason }}</p>
<table><thead><tr><th>#</th><th>Raw material</th><th>Quantity</th><th>Unit</th><th>Source unit cost</th><th>Expected supplier credit</th></tr></thead><tbody>
@php($total=\Brick\Math\BigDecimal::zero())
@php($hasAllCosts=true)
@foreach($return->lines as $line)
@php($total=$line->supplier_credit_value_snapshot===null?$total:$total->plus((string)$line->supplier_credit_value_snapshot))
@php($hasAllCosts=$hasAllCosts && $line->supplier_credit_value_snapshot!==null)
<tr><td>{{ $loop->iteration }}</td><td>{{ $line->material?->name }}</td><td>{{ $line->quantity }}</td><td>{{ $line->material?->unit }}</td><td>{{ $line->unit_price_snapshot??'Not captured' }}</td><td>{{ $line->supplier_credit_value_snapshot??'Unvalued' }}</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="5">Estimated total ({{ $return->currency?->code }})</th><th>{{ $hasAllCosts?(string)$total->toScale(4,\Brick\Math\RoundingMode::HalfUp):'Incomplete source costs' }}</th></tr></tfoot></table>
@if($return->status==='pending_landed_cost_review')
<p style="background:#fff0df;border:1px solid #e5be86;padding:12px">
<strong>Not yet physically posted.</strong> The requested returned quantities have not been deducted from warehouse inventory. Source-lot freight/customs allocations require a separately authorized inventory-asset credit and nonrecoverable expense debit before the supplier return can be released. The supplier base credit is a separate financial document.
</p>
@elseif($return->status==='cancelled')
<p style="background:#fff0df;padding:12px">This unposted supplier-return request was cancelled. No physical stock issue or landed-cost GL adjustment was generated.</p>
@else
<p style="background:#fff8e6;padding:12px">This return has been deducted from physical inventory. An independently approved supplier credit and payable adjustment must be recorded separately. The expected credit values above reflect original purchase prices, excluding freight/customs, settlement and currency conversion.</p>
@endif
@if($landedAdjustment)
<p><strong>Original-currency landed cost write-off:</strong>
{{ \App\Support\ReportingLabels::decimal($landedAdjustment->amount) }}
{{ $return->currency?->code }} ·
GL {{ $landedAdjustment->journal_number }} ·
Approval {{ $landedAdjustment->approval_reference }}</p>
@endif
<p><strong>Status:</strong>
@switch($return->financial_status)
@case('supplier_credit_posted')Supplier debit note posted@break
@case('landed_cost_review_pending')Pending independent landed-cost GL and warehouse return approval@break
@case('cancelled')Unposted request cancelled@break
@default Supplier credit pending @endswitch
</p>
@canany(['finance.update','administration.update'])
@if($return->financial_status==='supplier_credit_pending')
<p class="no-print"><a class="btn btn-light" href="{{ route('purchases.supplier-debit-notes.index') }}">Post finance-reviewed supplier debit note</a></p>
@endif
@if($return->status==='pending_landed_cost_review' && $lossExpenseLedgers->isNotEmpty())
<div class="no-print" style="border:1px solid #dce6f1;border-radius:10px;padding:16px;margin:14px 0">
<h3>Approve the original-currency landed-cost stock write-off</h3>
<p class="muted">A finance reviewer different from the return requester must confirm the original expense capitalization, source GRN quantities and exact returned share. This action posts the freight/customs loss to an approved expense account and removes all requested physical lines atomically. It does not create the supplier debit note.</p>
<form method="POST" action="{{ route('purchases.returns.landed-approve',$return) }}" style="display:grid;gap:12px">
@csrf
<label>Nonrecoverable returned landed-cost expense ledger
<select name="expense_ledger_id" required>
<option value="">Choose an independent expense account</option>
@foreach($lossExpenseLedgers as $ledger)
<option value="{{ $ledger->id }}">{{ $ledger->code }} — {{ $ledger->name }}</option>
@endforeach
</select></label>
<label>Signed approval reference<input type="text" name="approval_reference" minlength="4" maxlength="180" required></label>
<label>Original GRN, FIFO and landed-cost review evidence
<textarea name="source_evidence" minlength="20" maxlength="3000" rows="4" required></textarea></label>
<label style="display:flex;gap:8px;align-items:center">
<input type="checkbox" name="confirm_landed_expense" value="1" required>
I verified the returned original FIFO quantities, independently capitalized landed costs and separate supplier base-credit treatment.
</label>
<button class="btn" type="submit" onclick="return confirm('Post the independently reviewed landed-cost inventory write-off and then release the physical supplier return?')">Approve landed-cost GL and physical return</button>
</form></div>
@endif
@endcanany
@if($return->status==='pending_landed_cost_review' &&
   (auth()->user()->can('administration.update') ||
    (auth()->user()->can('purchases.update') && auth()->user()->can('inventory.update'))))
<form method="POST" action="{{ route('purchases.returns.cancel-pending',$return) }}" class="no-print"
 style="display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:16px 0">
@csrf
<label style="flex:1;min-width:240px">Reason to cancel an unposted return
<input name="reason" type="text" minlength="10" maxlength="1500" required></label>
<button class="btn btn-light" type="submit" onclick="return confirm('Cancel this pending return request? No warehouse or financial value will be changed.')">Cancel pending request</button>
</form>
@endif
<div style="margin-top:65px;display:flex;justify-content:space-between"><span>Warehouse officer: ___________________</span><span>Supplier acknowledgment: ___________________</span></div>
</div>
@endsection
