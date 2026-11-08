@extends('layouts.kanozay')
@section('content')
<div class="heading no-print">
 <div><h1>{{ $invoice->number }}</h1><p>Posted sales invoice and linked customer receivable</p></div>
 <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-light" href="{{ route('sales.invoices.index') }}">Back to invoices</a>@can('sales.create')<a class="btn btn-light" href="{{ route('sales.delivery-notes.create',['invoice_id'=>$invoice->id]) }}">Create delivery note</a>@endcan
@can('sales.create')<a class="btn btn-light" href="{{ route('sales.credit-notes.create',$invoice) }}">Return / credit note</a>@endcan<button class="btn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button></div>
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
@if($mayViewCosts)
<section class="card no-print" style="max-width:900px;margin:18px auto;padding:22px">
<h2>Factory source-currency inventory and COGS journals</h2>
<p class="muted">Restricted finance information. Revenue and customer receivables remain in {{ $invoice->currency_code }}. COGS is valued in each batch's original manufacturing currency. Different currencies are never combined or converted implicitly.</p>
<div class="table-wrap"><table><thead><tr>
<th>Production lot</th><th>Packages dispatched</th><th>Cost currency</th>
<th>COGS amount</th><th>Posted GL journal</th><th>Reconciliation</th>
</tr></thead><tbody>
@foreach($invoice->lines as $line)
@php($posting=$lineCosts->get($line->id))
<tr>
<td>{{ $line->batch?->batch_number??'Unknown source' }}</td>
<td>{{ number_format((int)$line->quantity_packages) }}</td>
<td>{{ $posting?->cost_currency_code??'Not costed' }}</td>
<td>@if($posting)<x-finance-amount :amount="$posting->amount" />@else — @endif</td>
<td>{{ $posting?->journal_number??'No source COGS journal' }}</td>
<td>@if($posting?->journal_status==='posted')
<strong style="color:#168058">Source cost posted</strong>
@else<strong style="color:#a3412b">Finance reconciliation required</strong>@endif</td>
</tr>
@endforeach
</tbody></table></div>
@if($costTotals)
<div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:14px">
@foreach($costTotals as $currency=>$amount)
<div style="padding:10px 13px;border-radius:9px;border:1px solid #dce7f0">
<strong>{{ $currency }}</strong> COGS posted: <x-finance-amount :amount="$amount" /></div>
@endforeach
</div>
@endif
@if($lineCosts->count()<$invoice->lines->count())
@canany(['finance.update','administration.update'])
<details style="border:1px solid #e6d8c5;border-radius:9px;padding:13px;margin:12px 0">
<summary>Reconcile missing original-currency COGS (finance only)</summary>
<p class="muted">This action may post COGS only where the source factory batch already had a fully reviewed GL capitalization on or before the original invoice date. All existing invoice and dispatch details remain immutable. Missing or later-dated cost evidence blocks the action.</p>
<form method="POST" action="{{ route('sales.invoices.cogs.reconcile',$invoice) }}" style="display:grid;gap:10px;max-width:580px">
@csrf
<label>Finance reconciliation reference<input type="text" name="reconciliation_reference" minlength="4" maxlength="180" required placeholder="Signed original-cost reconciliation file"></label>
<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="confirm_source_evidence" value="1" required> Verify the original dated finished inventory capitalization and unchanged batch quantities.</label>
<div><button class="btn" type="submit" onclick="return confirm('Post only missing original-currency COGS journals where previously capitalized factory cost and physical dispatch can be verified?')">Reconcile posted sale costs</button></div>
</form>
</details>
@endcanany
@endif
<p class="muted">Historic invoices that predate source manufacturing capitalization may have no posted COGS. Such exceptions require explicit journal and inventory reconciliation; this view cannot invent a retrospective source exchange rate.</p>
</section>
@endif
<section class="card no-print" style="max-width:900px;margin:18px auto;padding:22px">
<h2>Physical delivery and logistics progress</h2>
<p class="muted">Invoice posting already dispatches selected finished-good batches from stock. Delivery notes document where those invoiced packages went; they do not post another inventory movement or revenue journal.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:15px 0">
@foreach(['Documented in active notes'=>'documented','Customer receipt confirmed'=>'delivered','Returned in transit'=>'returned','Cancelled-note packages'=>'cancelled'] as $description=>$property)
<div style="border:1px solid #dce6f1;border-radius:8px;padding:12px"><div class="muted">{{ $description }}</div><strong style="font-size:20px">{{ number_format($logistics[$property]) }}</strong></div>
@endforeach
</div>
<div class="table-wrap"><table><thead><tr><th>Delivery note</th><th>Document date</th><th>Packages</th><th>Latest logistics status</th><th>Customer receipt evidence</th></tr></thead><tbody>
@forelse($deliveryNotes as $note)
<tr><td><a href="{{ route('sales.delivery-notes.show',$note) }}">{{ $note->number }}</a></td>
<td>{{ $note->delivery_date?->format('Y-m-d') }}</td>
<td>{{ $note->lines->sum('quantity_packages') }}</td>
<td>{{ ucfirst($note->status) }}</td>
<td>{{ $note->received_by ? $note->received_by.' · '.$note->delivered_at?->format('Y-m-d H:i') : 'Not confirmed' }}</td></tr>
@empty<tr><td colspan="5">No delivery-note documentation has been issued for this invoice.</td></tr>
@endforelse
</tbody></table></div>
<p class="muted">Customer-credit returns and their quarantine decisions remain separate from delivery-note status. Cancelling a delivery note never restores already invoiced inventory.</p>
</section>
<style>
@page{size:A4;margin:12mm}
@media print{body{background:#fff!important}.no-print,.top,.rail,.sidebar,.topbar,nav{display:none!important}.content,.main,.invoice-sheet{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;box-shadow:none!important;border:0!important}.invoice-sheet{break-inside:avoid}.invoice-sheet table{font-size:11pt}.invoice-sheet tr{break-inside:avoid}a{color:inherit;text-decoration:none}.invoice-sheet[dir="rtl"]{direction:rtl}.invoice-sheet[dir="rtl"] header>div:last-child{text-align:left!important}.invoice-sheet[dir="rtl"] th{text-align:right!important}}
</style>
@endsection
