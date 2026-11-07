@extends('layouts.kanozay')
@section('content')
<div class="heading no-print"><div><h1>{{ $creditNote->number }}</h1><p>Credit note for {{ $creditNote->invoice->number }}</p></div><div style="display:flex;gap:8px"><a class="btn btn-light" href="{{ route('sales.invoices.show',$creditNote->invoice) }}">Back to invoice</a><button class="btn" type="button" onclick="window.print()">Print / Save PDF</button></div></div>
<p class="no-print" style="margin:12px 0"><a class="btn btn-light" href="{{ route('sales.returns.quarantine.index') }}">Inspect quarantined customer returns</a></p>
<div class="card" style="max-width:900px;margin:auto;padding:36px;background:#fff;color:#1b2738"><header style="display:flex;align-items:center;gap:20px;border-bottom:3px solid #5921a8;padding-bottom:16px"><img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay logo" style="max-width:200px;max-height:86px;object-fit:contain"><div><h2>Kanozay Foods Manufacturing Company</h2><strong>CREDIT NOTE</strong><div>{{ $creditNote->number }}</div></div></header>
<div style="display:flex;justify-content:space-between;margin:24px 0"><div><strong>Customer</strong><div>{{ $creditNote->invoice->customer->name }}</div><strong>Original invoice</strong><div>{{ $creditNote->invoice->number }}</div></div><div><strong>Date</strong><div>{{ $creditNote->credit_date->format('Y-m-d') }}</div><strong>Reason</strong><div>{{ $creditNote->reason }}</div></div></div>
<div class="table-wrap"><table style="width:100%"><thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Credit amount</th><th>Disposition</th></tr></thead><tbody>@foreach($creditNote->lines as $line)<tr><td>{{ $line->invoiceLine->finishedGood->name }} · Batch {{ $line->invoiceLine->batch->batch_number }}</td><td>{{ number_format($line->quantity_packages) }}</td><td>{{ $creditNote->currency_code }} {{ $line->unit_price }}</td><td>{{ $creditNote->currency_code }} {{ $line->line_total }}</td><td>{{ $line->restocked?'QC accepted to stock':'Quarantined / not restocked' }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="3" style="text-align:right">Total credit</th><th>{{ $creditNote->currency_code }} {{ $creditNote->total }}</th><th></th></tr></tfoot></table></div>
@if($creditNote->refund)
<div style="margin-top:20px;padding:12px;border:1px solid #d8e5f2;background:#f3f9ff;border-radius:9px">
<h3>Posted customer refund</h3>
<p>Amount: {{ $creditNote->currency_code }} {{ $creditNote->refund->amount }}</p>
<p>Source: {{ $creditNote->refund->source?->name }} · Authorization: {{ $creditNote->refund->authorization_reference }}</p>
<p>Payment journal: {{ $creditNote->refund->transaction?->journal?->number }}</p>
</div>
@endif
@if($creditNote->tradeDocumentCredit)
<p style="margin-top:15px">Credit against original unpaid invoice: {{ $creditNote->currency_code }} {{ $creditNote->tradeDocumentCredit->amount }}</p>
@endif
<div style="margin-top:60px;max-width:280px;text-align:center;border-top:1px solid #667085;padding-top:8px">Authorized signature</div></div><style>@page{size:A4;margin:12mm}@media print{.no-print,.rail,.sidebar,.topbar,nav{display:none!important}.content,.main,.card{max-width:none!important;margin:0!important;padding:0!important;box-shadow:none!important;border:0!important}tr{break-inside:avoid}}</style>
@endsection
