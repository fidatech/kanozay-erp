@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Matched supplier invoice {{ $invoice->number }}</h1><p>Posted payable matched to purchase order and goods receipt</p></div><div class="actions"><a class="btn btn-light" href="{{ route('workspace.page',['purchases','goods-receipts']) }}">Back</a><button class="btn" onclick="window.print()">Print / PDF</button></div></div>
<article class="card" style="max-width:900px;margin:auto;padding:28px;background:white">
<div style="display:flex;gap:20px;align-items:center;border-bottom:3px solid #217756;padding-bottom:15px"><img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay" width="140"><h2>Kanozay Foods Manufacturing Company</h2></div>
<p><strong>Supplier:</strong> {{ $invoice->supplier?->name }} · <strong>Supplier invoice:</strong> {{ $invoice->supplier_invoice_number }}</p>
<p><strong>Purchase order:</strong> {{ $invoice->order?->number }} · <strong>Receipt:</strong> {{ $invoice->receipt?->number }}</p>
<p><strong>Date:</strong> {{ $invoice->invoice_date?->format('Y-m-d') }} · <strong>Due:</strong> {{ $invoice->due_date?->format('Y-m-d') }}</p>
<div class="table-wrap"><table><thead><tr><th>Material</th><th>Quantity</th><th>Unit price snapshot</th><th>Source line value</th></tr></thead><tbody>
@foreach($invoice->lines as $line)<tr><td>{{ $line->material?->name }}</td><td>{{ $line->quantity }}</td><td>{{ $line->unit_price_snapshot }}</td><td>{{ $line->line_value }}</td></tr>@endforeach
</tbody><tfoot><tr><th colspan="3">Total ({{ $invoice->currency?->code }})</th><th>{{ $invoice->total }}</th></tr></tfoot></table></div>
<p>Journal: {{ $invoice->journal?->number }} · {{ $invoice->journal?->status }} · Trade payable: {{ $invoice->payable?->number }}</p>
<p class="muted">Financial recognition only. Source receipt remains unchanged; freight, tax, advances and currency conversion are reconciled separately.</p>
</article>
<style>@page{size:A4;margin:12mm}@media print{.heading,.topbar,.sidebar,nav{display:none!important}article{max-width:none!important;box-shadow:none!important;border:0!important;padding:0!important}}</style>
@endsection