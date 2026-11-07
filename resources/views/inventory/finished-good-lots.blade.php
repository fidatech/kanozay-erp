@extends('layouts.kanozay')
@section('content')
<style>
.fl-panel{background:white;border:1px solid #dce7f2;border-radius:12px;padding:21px;margin:14px 0}
.fl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.fl-grid label{display:grid;gap:6px;font-size:12px;color:#405d77}
.fl-grid input,.fl-grid select,.fl-grid textarea{box-sizing:border-box;width:100%;border:1px solid #d8e3ee;border-radius:8px;padding:10px}
.fl-table{overflow:auto}.fl-table table{min-width:920px;width:100%;font-size:12px}
.fl-table th,.fl-table td{padding:11px}
.fl-warning{color:#785923;background:#fff8e8;border-radius:8px;padding:12px;font-size:12px;line-height:1.7}
@media(max-width:850px){.fl-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Finished Goods Lot & Expiry Controls</h1><p>Manufacturing batch traceability, shelf life, quality holds and recall restrictions</p></div>
<a class="btn btn-light" href="{{ route('production.finished-goods.index') }}">Finished goods stock</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<p class="fl-warning">Only recorded expiry dates are enforced; legacy product batches without a validated shelf-life specification remain in their existing release workflow. A hold or recall blocks further dispatch and restocking. Recalls preserve the original invoice and customer traceability records; they do not automatically refund customers or destroy stock. Recalled lots cannot be reopened.</p>
@canany(['inventory.update','laboratory.update','administration.update'])
<section class="fl-panel"><h2>Register released batch shelf-life record</h2>
<form method="POST" class="fl-grid" action="{{ route('inventory.finished-good-lots.store') }}">@csrf
<label>Released batch<select name="production_batch_id" required><option value="">Choose batch</option>
@foreach($batches as $b)<option value="{{ $b->id }}">{{ $b->batch_number }} · {{ $b->finishedGood?->sku }}</option>@endforeach</select></label>
<label>Manufactured on<input type="date" name="manufactured_on" max="{{ today()->toDateString() }}"></label>
<label>Expiry date<input type="date" name="expires_on"></label>
<label>Document / batch trace reference<input name="traceability_reference" maxlength="160"></label>
<label>Approved authorization reference<input name="authorization_ref" minlength="3" maxlength="180" required></label>
<label>Inspection notes<textarea name="risk_notes" maxlength="3000"></textarea></label>
<button class="btn">Register batch control</button>
</form></section>
@endcanany
<section class="fl-panel"><h2>Controlled finished goods and release state</h2>
<div class="fl-table"><table><thead><tr><th>Batch</th><th>Finished product</th><th>Accepted packages</th><th>Manufactured</th><th>Expires</th><th>Lot status</th><th>Trace reference</th><th>QC decision</th></tr></thead><tbody>
@forelse($lots as $lot)<tr><td>{{ $lot->batch_number }}</td><td>{{ $lot->sku }} · {{ $lot->product_name }}</td><td>{{ $lot->accepted_packages }}</td><td>{{ $lot->manufactured_on??'Not recorded' }}</td><td>{{ $lot->expires_on??'Not recorded' }}</td><td><strong>{{ strtoupper($lot->status) }}</strong>@if($lot->expires_on && $lot->expires_on<=today()->toDateString())<br><small>Past recorded expiry</small>@endif</td><td>{{ $lot->traceability_reference??'—' }}</td>
<td>
@canany(['inventory.update','laboratory.update','administration.update'])
@if($lot->status!=='recalled')
<form method="POST" action="{{ route('inventory.finished-good-lots.decision',$lot->production_batch_id) }}" style="display:grid;gap:6px;min-width:200px">@csrf
<select name="action" required>
@if($lot->status==='active')<option value="hold">Quality hold</option><option value="recall">Permanent recall</option>@endif
@if($lot->status==='held')<option value="release">Independent QC release</option><option value="recall">Permanent recall</option>@endif
</select>
<input name="authorization_ref" minlength="3" maxlength="180" placeholder="Approval reference" required>
<textarea name="reason" minlength="10" maxlength="3000" placeholder="Detailed QC / recall reason" required></textarea>
<button class="btn btn-secondary" onclick="return confirm('Record this lot quality decision?')">Record decision</button>
</form>
@endif
@endcanany
</td></tr>
@empty<tr><td colspan="8">No finished goods lot-control records entered yet.</td></tr>@endforelse
</tbody></table></div>{{ $lots->links() }}</section>
<section class="fl-panel"><h2>Immutable lot-control history</h2><div class="fl-table"><table><thead><tr><th>When</th><th>Batch</th><th>Action</th><th>Prior state</th><th>New state</th><th>Reason</th><th>Authority</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->batch_number }}</td><td>{{ ucfirst($event->action) }}</td><td>{{ $event->previous_status??'—' }}</td><td>{{ $event->new_status }}</td><td>{{ $event->reason }}</td><td>{{ $event->authorization_ref }}</td></tr>
@empty<tr><td colspan="7">No recorded QC lot decisions.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
