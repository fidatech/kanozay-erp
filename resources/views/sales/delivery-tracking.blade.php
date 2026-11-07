@extends('layouts.kanozay')
@section('content')
<style>
.dt-card{background:#fff;border:1px solid #dfebf3;border-radius:11px;padding:22px;margin:15px 0}
.dt-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.dt-fields label{display:grid;gap:6px;font-size:12px;color:#49647c;font-weight:700}
.dt-fields input,.dt-fields select,.dt-fields textarea{width:100%;padding:10px;border:1px solid #d9e5ee;border-radius:7px}
.dt-table{overflow:auto}.dt-table table{min-width:760px;font-size:12px}.dt-table td,.dt-table th{padding:12px}
@media(max-width:800px){.dt-fields{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Delivery Tracking</h1><p>Shipment milestones, verified proof of receipt and source delivery-note history</p></div>
<a class="btn btn-light" href="{{ route('workspace.page',['sales','delivery-notes']) }}">Delivery notes</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<section class="dt-card"><h2>Filter delivery events</h2>
<form method="GET" action="{{ route('workspace.page',['sales','delivery-tracking']) }}" style="display:flex;gap:10px;flex-wrap:wrap">
<select name="note_id"><option value="">All delivery notes</option>
@foreach($notes as $note)<option value="{{ $note->id }}" @selected($selected?->id===$note->id)>{{ $note->number }} · {{ $note->invoice?->customer?->name }}</option>@endforeach
</select><button class="btn">View</button></form></section>
@canany(['sales.update','administration.update'])
<section class="dt-card"><h2>Record logistics milestone</h2><p class="muted">Confirming delivery requires both customer receiver name and proof reference. Tracking is append-only. No duplicate inventory or financial posting occurs.</p>
<form method="POST" action="{{ route('sales.delivery-tracking.store') }}" class="dt-fields">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Delivery note<select name="sales_delivery_note_id" required><option value="">Select issued note</option>
@foreach($notes as $note)@if($note->status==='issued')<option value="{{ $note->id }}" @selected($selected?->id===$note->id)>{{ $note->number }} · {{ $note->invoice?->customer?->name }}</option>@endif @endforeach
</select></label>
<label>Milestone<select name="status"><option value="loaded">Loaded</option><option value="in_transit">In transit</option><option value="attempted">Delivery attempted</option><option value="delivered">Delivered</option><option value="returned">Returned to depot</option></select></label>
<label>Actual event time<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></label>
<label>Checkpoint / location<input name="location" maxlength="180" required></label>
<label>Proof reference<input name="proof_reference" maxlength="180" placeholder="Required for delivery or return"></label>
<label>Customer receiver<input name="receiver_name" maxlength="150" placeholder="Required for delivery"></label>
<label style="grid-column:1/-1">Transport notes<textarea name="notes" rows="2" maxlength="3000"></textarea></label>
<button class="btn" type="submit">Save tracking event</button></form></section>
@endcanany
<section class="dt-card"><h2>Recorded shipment milestones</h2><div class="dt-table"><table><thead><tr><th>Delivery note</th><th>Customer</th><th>Milestone</th><th>Occurred at</th><th>Checkpoint</th><th>Proof / receiver</th><th>Recorded by</th></tr></thead><tbody>
@forelse($events as $event)<tr>
<td><a href="{{ route('sales.delivery-notes.show',$event->note) }}">{{ $event->note?->number }}</a></td>
<td>{{ $event->note?->invoice?->customer?->name }}</td><td>{{ ucwords(str_replace('_',' ',$event->status)) }}</td>
<td>{{ $event->occurred_at?->format('Y-m-d H:i') }}</td><td>{{ $event->location }}</td>
<td>{{ $event->proof_reference??'—' }} · {{ $event->receiver_name??'—' }}</td><td>{{ $event->actor?->name }}</td></tr>
@empty<tr><td colspan="7">No delivery milestones logged.</td></tr>@endforelse
</tbody></table></div>{{ $events->links() }}</section>
@endsection