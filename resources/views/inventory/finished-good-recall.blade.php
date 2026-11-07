@extends('layouts.kanozay')
@section('content')
<style>
.fr-panel{padding:22px;background:white;border:1px solid #dce7f2;border-radius:12px;margin:16px 0}
.fr-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:15px}
.fr-stat{padding:16px;border:1px solid #dfe7ee;border-radius:10px;text-align:center;background:#f8fafc}
.fr-stat strong{font-size:22px;display:block}
.fr-stat small{color:#52687c}
.fr-table{overflow:auto}.fr-table table{width:100%;min-width:1050px;font-size:12px}
.fr-table th,.fr-table td{padding:11px;vertical-align:top}
.fr-warning{padding:12px;border-radius:8px;background:#fff3e5;color:#74451c;font-size:12px}
.fr-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.fr-form input,.fr-form select,.fr-form textarea{box-sizing:border-box;width:100%;padding:8px;border:1px solid #dbe4f0;border-radius:6px}
.fr-form textarea,.fr-form button{grid-column:1/-1}
@media(max-width:850px){.fr-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="heading"><div><h1>Batch recall impact · {{ $batch->batch_number }}</h1><p>{{ $batch->finishedGood?->sku }} · {{ $batch->finishedGood?->name }}</p></div>
<div style="display:flex;gap:8px"><a class="btn btn-light" href="{{ route('inventory.finished-good-lots.index') }}">Lot controls</a>
<a class="btn" href="{{ route('inventory.finished-good-recall.export',$batch) }}">Export affected customers</a></div></div>
<div class="fr-warning">This batch is permanently recalled. The report lists original customer invoices and later credit notes; it cannot confirm packages have physically been recovered. Customer contact, reverse logistics, disposal and refunds require independently documented actions. Exported personal contact details must be handled only by authorized recall staff.</div>
<div class="fr-grid">
<div class="fr-stat"><strong>{{ $total }}</strong><small>Invoiced packages</small></div>
<div class="fr-stat"><strong>{{ $credited }}</strong><small>Credited packages</small></div>
<div class="fr-stat"><strong>{{ $contacts->notified_count??0 }}</strong><small>Contacted invoice lines</small></div>
<div class="fr-stat"><strong>{{ $contacts->recovered_count??0 }}</strong><small>Reported recovery follow-ups</small></div>
</div>
<section class="fr-panel"><h2>Traceable invoices and recall follow-ups</h2>
<div class="fr-table"><table><thead><tr><th>Invoice</th><th>Customer</th><th>Contact details</th><th>Invoiced</th><th>Credited</th><th>Delivery note qty</th><th>Delivered qty</th><th>Contact status</th><th>Action</th></tr></thead><tbody>
@forelse($lines as $line)
@php($deliveryRow=$delivery->get($line->invoice_line_id))
<tr>
<td><a href="{{ route('sales.invoices.show',$line->invoice_id) }}">{{ $line->invoice_number }}</a><br><small>{{ $line->invoice_date }}</small></td>
<td>{{ $line->customer_name }}</td>
<td>{{ $line->customer_phone?:'No phone' }}<br>{{ $line->customer_email?:'No email' }}<br>{{ $line->customer_address }}</td>
<td>{{ $line->quantity_packages }}</td>
<td>{{ $returned[$line->invoice_line_id]??0 }}</td>
<td>{{ $deliveryRow?->documented_packages??0 }}</td>
<td>{{ $deliveryRow?->delivered_packages??0 }}</td>
<td>{{ strtoupper($line->contact_status??'pending') }}<br><small>{{ $line->evidence_reference??'' }}</small></td>
<td>
@if($line->contact_status!=='recovered')
@if(auth()->user()->can('administration.update')||(auth()->user()->can('sales.update')&&auth()->user()->can('laboratory.view')))
<form method="POST" class="fr-form" action="{{ route('inventory.finished-good-recall.contact',[$batch,$line->invoice_line_id]) }}">@csrf
<select name="status" required>
@if(!$line->contact_status)<option value="contacted">Customer contacted</option>
@else<option value="contacted">Update contact</option><option value="recovered">Recovery follow-up documented</option>@endif
</select>
<select name="method"><option value="phone">Phone</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="in_person">In person</option><option value="letter">Letter</option></select>
<input name="evidence_reference" minlength="3" maxlength="180" placeholder="Contact / proof reference" required>
<textarea name="notes" maxlength="3000" placeholder="Evidence and actual next action"></textarea>
<button class="btn btn-secondary" type="submit">Save follow-up</button></form>
@endif
@endif
</td></tr>
@empty<tr><td colspan="9">No customer invoices were issued against this batch.</td></tr>@endforelse
</tbody></table></div>{{ $lines->links() }}</section>
@endsection
