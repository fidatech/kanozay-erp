@extends('layouts.kanozay')
@section('content')
<style>
.sa-panel{padding:20px;border-radius:12px;border:1px solid #dce8f3;background:white;margin:14px 0}
.sa-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.sa-grid label{display:grid;gap:6px;font-size:12px;color:#3c5d7f}
.sa-grid input,.sa-grid select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d7e1ea;border-radius:8px}
.sa-table{overflow:auto}.sa-table table{width:100%;min-width:800px;font-size:12px}
.sa-table th,.sa-table td{padding:11px}
.sa-warning{color:#755b28;background:#fff8e8;padding:12px;font-size:12px;border-radius:8px;line-height:1.65}
@media(max-width:850px){.sa-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Supplier Advances</h1><p>Cash and bank prepayments, supplier advance assets and documented payable applications</p></div>
<a class="btn btn-light" href="{{ route('finance.money.index') }}">Financial cash transactions</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<div class="sa-warning">An advance payment is a posted journal: debit supplier-advance asset and credit the source cash/bank/sarafi ledger in the same currency. Applying it to a supplier invoice posts a separate payable-clearing journal. Earlier purchase-order advance notes are operational estimates, not proof that money was paid; never add those twice to financial balances.</div>
@if(auth()->user()->can('administration.update') || (auth()->user()->can('purchases.update') && auth()->user()->can('finance.update')))
<section class="sa-panel"><h2>Post a new supplier advance payment</h2>
<form method="POST" action="{{ route('supplier-advances.pay') }}" class="sa-grid">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Confirmed purchase order<select name="purchase_order_id" required><option value="">Select PO</option>
@foreach($orders as $o)<option value="{{ $o->id }}">{{ $o->number }} / {{ $o->supplier?->name }} / {{ $o->currency?->code }}</option>@endforeach
</select></label>
<label>Paid from cash / bank / sarafi<select name="source_account_id" required><option value="">Choose same-currency source account</option>
@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->currency?->code }}</option>@endforeach
</select></label>
<div class="sa-warning">The supplier-specific advance asset account is selected automatically from the purchase order currency. It also appears in the supplier statement.</div>
<label>Payment date<input type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Payment amount<input name="amount" inputmode="decimal" required></label>
<label>Approved payment reference<input name="reference" minlength="3" maxlength="120" required></label>
<button class="btn" type="submit" onclick="return confirm('Post the supplier advance as a real cash payment to the General Ledger?')">Post supplier advance</button>
</form></section>
@endif
<section class="sa-panel"><h2>Posted supplier prepayments and unallocated balances</h2>
<div class="sa-table"><table><thead><tr><th>Advance</th><th>Supplier</th><th>PO</th><th>Paid date</th><th>Currency</th><th>Original amount</th><th>Remaining</th><th>Cash reference</th></tr></thead><tbody>
@forelse($advances as $a)<tr><td>{{ $a->number }}</td><td>{{ $a->supplier_name }}</td><td><a href="{{ route('purchases.orders.show',$a->purchase_order_id) }}">{{ $a->po_number }}</a></td><td>{{ $a->paid_on }}</td><td>{{ $a->currency_code }}</td><td>{{ $a->amount }}</td><td><strong>{{ $a->remaining }}</strong></td><td>{{ $a->reference }}</td></tr>
@empty<tr><td colspan="8">No actual supplier advance payments posted yet.</td></tr>@endforelse
</tbody></table></div>{{ $advances->links() }}</section>
@if(auth()->user()->can('administration.update') || (auth()->user()->can('purchases.update') && auth()->user()->can('finance.update')))
<section class="sa-panel"><h2>Apply prepayment to a verified supplier payable</h2>
<p>Only applications against the same supplier and currency will post. Both the advance balance and the invoice's unpaid balance are checked under transactional locks.</p>
@foreach($advances as $a)
@if(\Brick\Math\BigDecimal::of((string)$a->remaining)->isPositive())
<details style="padding:12px;border:1px solid #e3eaf3;border-radius:8px;margin:9px 0">
<summary><strong>{{ $a->number }}</strong> · {{ $a->supplier_name }} · Available {{ $a->currency_code }} {{ $a->remaining }}</summary>
<form method="POST" action="{{ route('supplier-advances.apply',$a->id) }}" class="sa-grid" style="margin-top:14px">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Payable document<select name="trade_document_id" required><option value="">Select matching supplier invoice</option>
@foreach($payables as $doc)
@if($doc->trade_party_id==$a->supplier_id && $doc->currency_id==$a->currency_id)
<option value="{{ $doc->id }}">{{ $doc->number }} · {{ $doc->currency?->code }} {{ $doc->amount }}</option>
@endif
@endforeach</select></label>
<label>Application date<input type="date" name="applied_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Apply amount<input name="amount" inputmode="decimal" required></label>
<label>Finance approval reference<input name="approval_reference" minlength="4" maxlength="180" required></label>
<button class="btn" type="submit" onclick="return confirm('Clear this amount against the supplier payable journal?')">Apply to payable</button>
</form></details>
@endif
@endforeach
</section>
@endif
<section class="sa-panel"><h2>Posted supplier advance applications</h2>
<div class="sa-table"><table><thead><tr><th>Application</th><th>Advance</th><th>Payable</th><th>Date</th><th>Amount</th><th>Authorization</th></tr></thead><tbody>
@forelse($applications as $x)<tr><td>{{ $x->number }}</td><td>{{ $x->advance_number }}</td><td>{{ $x->payable_number }}</td><td>{{ $x->applied_on }}</td><td>{{ $x->amount }}</td><td>{{ $x->approval_reference }}</td></tr>
@empty<tr><td colspan="6">No applications posted yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
