@extends('layouts.kanozay')
@section('content')
<style>
.sd-panel{background:white;border:1px solid #dce8f1;border-radius:12px;padding:20px;margin-bottom:16px}
.sd-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.sd-form label{display:grid;gap:5px;font-size:12px;color:#46617c}
.sd-form input,.sd-form select,.sd-form textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #dae4ee;border-radius:8px}
.sd-table{overflow:auto}.sd-panel table{min-width:780px;font-size:12px}.sd-panel th,.sd-panel td{padding:11px}
.sd-warning{background:#fff7e8;border-radius:8px;padding:12px;font-size:12px;color:#77572c}
@media(max-width:780px){.sd-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Supplier Debit Notes</h1><p>Approved financial settlement of physical goods returns</p></div><a class="btn btn-light" href="{{ route('workspace.page',['purchases','purchase-returns']) }}">Purchase returns</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@canany(['finance.update','administration.update'])
<section class="sd-panel"><h2>Post supplier confirmed debit note</h2>
<p class="sd-warning">Select the original supplier and matching-currency payable. Where a GRN has verified supplier invoices, only the payables and inventory asset ledger linked to that GRN may be credited. The credit amount cannot exceed either its remaining payable balance or the original returned material's purchase-price snapshot. Choose the approved balancing inventory/expense account, supported by a supplier acknowledgment. This posts a balanced GL journal and updates outstanding payables exactly once.</p>
@forelse($returns as $return)
<details style="padding:12px;border:1px solid #e3eaf3;border-radius:8px;margin:10px 0">
<summary><strong>{{ $return->number }}</strong> · {{ $return->order?->supplier?->name }} ({{ $return->currency?->code }})</summary>
<form method="POST" class="sd-form" action="{{ route('purchases.supplier-debit-notes.post',$return) }}" style="margin-top:13px">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
@php($linkedForReturn=$matchedByReceipt->get($return->goods_receipt_id,collect()))
<label>Matching payable<select name="trade_document_id" required data-return-payable><option value="">Select a payable</option>
@foreach($payables as $p)
@if($p->trade_party_id===$return->order?->supplier_id && $p->currency_id===$return->currency_id && ($linkedForReturn->isEmpty() || $linkedForReturn->contains('trade_document_id',$p->id)))
@php($link=$linkedForReturn->firstWhere('trade_document_id',$p->id))
<option value="{{ $p->id }}" data-ledger="{{ $link?->inventory_ledger_id??'' }}">{{ $p->number }} · {{ $p->amount }} · {{ $p->party?->name }}</option>
@endif
@endforeach</select></label>
<label>Credit offset account<select name="credit_offset_ledger_id" data-return-offset required><option value="">Finance-approved account</option>
@foreach($offsetLedgers as $ledger)<option value="{{ $ledger->id }}">{{ $ledger->code }} · {{ $ledger->name }}</option>@endforeach</select></label>
<label>Credit date<input name="credit_date" type="date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Approved amount<input name="amount" inputmode="decimal" required></label>
<label>Supplier confirmation ref<input name="supplier_credit_reference" required minlength="3" maxlength="180"></label>
<label>Finance review notes<input name="review_notes" required minlength="10" maxlength="3000"></label>
<button class="btn" type="submit" onclick="return confirm('Post debit to supplier payable and credit to selected inventory/expense ledger?')">Post supplier credit</button></form></details>
@empty<p>No pending supplier returns are available for a financial debit note.</p>@endforelse
</section>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('select[data-return-payable]').forEach(payable=>{
  const form=payable.closest('form');
  const offset=form?.querySelector('select[data-return-offset]');
  if(!offset)return;
  const sync=()=>{
   const ledger=payable.selectedOptions[0]?.dataset.ledger;
   [...offset.options].forEach(option=>{
    if(option.value)option.hidden=!!ledger && option.value!==ledger;
   });
   if(ledger){
    offset.value=ledger;
   }else if(offset.selectedOptions[0]?.hidden){
    offset.value='';
   }
   if(window.jQuery && window.jQuery.fn.select2){
    window.jQuery(offset).trigger('change.select2');
   }
  };
  payable.addEventListener('change',sync);
  sync();
 });
});
</script>
@endcanany
<section class="sd-panel"><h2>Posted supplier debit notes</h2>
<div class="sd-table"><table><thead><tr><th>Debit note</th><th>Return</th><th>Supplier</th><th>Credit date</th><th>Amount</th><th>Supplier reference</th><th>GL journal</th><th>Payable</th></tr></thead><tbody>
@forelse($notes as $n)<tr><td>{{ $n->number }}</td><td>{{ $n->purchaseReturn?->number }}</td><td>{{ $n->document?->party?->name }}</td><td>{{ $n->credit_date?->toDateString() }}</td><td>{{ $n->amount }} {{ $n->currency?->code }}</td><td>{{ $n->supplier_credit_reference }}</td><td>{{ $n->journal?->number }}</td><td>{{ $n->document?->number }}</td></tr>
@empty<tr><td colspan="8">No posted supplier debit notes.</td></tr>@endforelse
</tbody></table></div>{{ $notes->links() }}</section>
@endsection
