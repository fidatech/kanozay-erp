@extends('layouts.kanozay')
@section('content')
<div class="heading no-print">
 <div><h1>{{ $invoice->number }}</h1><p>Posted sales invoice and linked customer receivable</p></div>
 <div style="display:flex;gap:8px"><a class="btn btn-light" href="{{ route('sales.invoices.index') }}">Back to invoices</a>@can('sales.create')<a class="btn btn-light" href="{{ route('sales.credit-notes.create',$invoice) }}">Return / credit note</a>@endcan<button class="btn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button></div>
</div>
<div class="card invoice-sheet" dir="{{ session('locale','en') === 'en' ? 'ltr' : 'rtl' }}" style="max-width:900px;margin:0 auto;padding:42px;background:#fff;color:#1b2738">
 <header style="display:flex;align-items:center;justify-content:space-between;gap:24px;border-bottom:3px solid #5921a8;padding-bottom:18px">
  <div style="display:flex;align-items:center;gap:18px"><img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay Foods Manufacturing" style="width:210px;max-height:92px;object-fit:contain">
   <div><h1 style="margin:0;color:#5921a8">{{ $company?->legal_name ?: ($company?->name ?: 'Kanozay Foods Manufacturing Company') }}</h1>
    @if($company?->address)<div>{{ $company->address }}</div>@endif
    <div>{{ collect([$company?->phone, $company?->email, $company?->website])->filter()->implode(' · ') }}</div>
   </div>
  </div>
  <div style="text-align:right;min-width:150px"><strong style="font-size:22px">INVOICE</strong><div>{{ $invoice->number }}</div><div>{{ $invoice->invoice_date->format('Y-m-d') }}</div></div>
 </header>
 <section style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:25px 0">
  <div><strong>Bill to</strong><div style="font-size:18px">{{ $invoice->customer->name }}</div>
   @if($invoice->customer->contact_person)<div>Attn: {{ $invoice->customer->contact_person }}</div>@endif
   @if($invoice->customer->address)<div>{{ $invoice->customer->address }}</div>@endif
   @if($invoice->customer->phone)<div>{{ $invoice->customer->phone }}</div>@endif
  </div>
  <div style="text-align:right"><div><strong>Due date:</strong> {{ $invoice->due_date->format('Y-m-d') }}</div>
   <div><strong>Currency:</strong> {{ $invoice->currency_code }}</div>
   @if($invoice->lines->first()?->dispatch?->reference)<div><strong>Dispatch:</strong> {{ $invoice->lines->first()->dispatch->reference }}</div>@endif
   @if($invoice->tradeDocument?->reference)<div><strong>Customer reference:</strong> {{ $invoice->tradeDocument->reference }}</div>@endif
  </div>
 </section>
 <div class="table-wrap"><table style="width:100%"><thead><tr><th style="text-align:left">Product description</th><th>Quantity</th><th>Unit</th><th style="text-align:right">Unit price</th><th style="text-align:right">Line total</th></tr></thead><tbody>
  @foreach($invoice->lines as $line)
   <tr><td>{{ $line->finishedGood->name }}<div class="muted">{{ $line->finishedGood->sku }} · Batch {{ $line->batch->batch_number }}</div></td>
    <td style="text-align:center">{{ number_format($line->quantity_packages) }}</td><td style="text-align:center">{{ $line->finishedGood->package_unit }}</td>
    <td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim((string) $line->unit_price, '0'), '.') }}</td>
    <td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim((string) $line->line_total, '0'), '.') }}</td></tr>
  @endforeach
 </tbody></table></div>
 <section style="display:flex;justify-content:flex-end;margin-top:20px">
  <table style="width:min(100%,440px)"><tbody>
   <tr><th style="text-align:left">Subtotal</th><td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim((string) $invoice->subtotal, '0'), '.') }}</td></tr>
   <tr><th style="text-align:left">Invoice total</th><td style="text-align:right"><strong>{{ $invoice->currency_code }} {{ rtrim(rtrim((string) $invoice->total, '0'), '.') }}</strong></td></tr>
   <tr><th style="text-align:left">Previous balance</th><td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim($previousBalance, '0'), '.') }}</td></tr>
   <tr><th style="text-align:left">Payments received</th><td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim($paid, '0'), '.') }}</td></tr>
   <tr><th style="text-align:left">Credit notes</th><td style="text-align:right">{{ $invoice->currency_code }} {{ rtrim(rtrim($credited, '0'), '.') }}</td></tr>
   <tr><th style="text-align:left">Outstanding on invoice</th><td style="text-align:right"><strong>{{ $invoice->currency_code }} {{ rtrim(rtrim($outstanding, '0'), '.') }}</strong></td></tr>
   <tr><th style="text-align:left">Total customer balance</th><td style="text-align:right"><strong>{{ $invoice->currency_code }} {{ rtrim(rtrim((string) \Brick\Math\BigDecimal::of($previousBalance)->plus($outstanding)->toScale(4), '0'), '.') }}</strong></td></tr>
  </tbody></table>
 </section>
 @if($invoice->creditNotes->isNotEmpty())<section style="margin-top:26px"><strong>Credit notes</strong><div class="table-wrap"><table><thead><tr><th>Number</th><th>Date</th><th>Reason</th><th>Amount</th></tr></thead><tbody>@foreach($invoice->creditNotes as $credit)<tr><td><a href="{{ route('sales.credit-notes.show',$credit) }}">{{ $credit->number }}</a></td><td>{{ $credit->credit_date->format('Y-m-d') }}</td><td>{{ $credit->reason }}</td><td>{{ $invoice->currency_code }} {{ rtrim(rtrim((string)$credit->total,'0'),'.') }}</td></tr>@endforeach</tbody></table></div></section>@endif
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:80px;margin-top:70px;text-align:center">
  <div style="border-top:1px solid #667085;padding-top:8px">Prepared by</div><div style="border-top:1px solid #667085;padding-top:8px">Customer signature</div>
 </div>
</div>
<style>
@page{size:A4;margin:12mm}
@media print{body{background:#fff!important}.no-print,.top,.rail,.sidebar,.topbar,nav{display:none!important}.content,.main,.invoice-sheet{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;box-shadow:none!important;border:0!important}.invoice-sheet{break-inside:avoid}.invoice-sheet table{font-size:11pt}.invoice-sheet tr{break-inside:avoid}a{color:inherit;text-decoration:none}.invoice-sheet[dir="rtl"]{direction:rtl}.invoice-sheet[dir="rtl"] header>div:last-child{text-align:left!important}.invoice-sheet[dir="rtl"] th{text-align:right!important}}
</style>
@endsection
