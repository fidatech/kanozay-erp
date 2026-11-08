@extends('layouts.kanozay')
@section('content')
<style>.qh-panel{padding:20px;background:white;border:1px solid #dce6f2;border-radius:12px}
.qh-panel table{min-width:850px;font-size:12px}.qh-panel th,.qh-panel td{padding:11px}
.qh-wrap{overflow:auto}.qh-note{padding:12px;border-radius:8px;background:#fff7e7;font-size:12px;color:#775523;line-height:1.7}</style>
<div class="heading"><div><h1>Receipt-lot QC holds</h1><p>Goods-receipt batches and their laboratory release status</p></div><a class="btn btn-light" href="{{ route('workspace.page',['laboratory','incoming']) }}">Incoming laboratory QC</a></div>
@if(session('success'))<p class="notice" role="status">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning" role="alert">{{ $errors->first() }}</p>@endif
<section class="qh-panel">
<p class="qh-note">An incoming-material lot with required QC specifications is held until its collected sample passes all specifications frozen at collection time. An unreviewed or rejected sample also holds the lot. A quality manager can place an additional manual hold, which requires another authorized person to clear it with documented evidence. Supplier returns remain possible against source lots. Clearing a manual hold does not bypass missing or failed laboratory results.</p>
<div class="qh-wrap"><table><thead><tr><th>Raw material</th><th>Receipt</th><th>Lot valuation status</th><th>Remaining quantity</th><th>QC sample</th><th>Manual restriction</th><th>Material release</th><th>Review action</th></tr></thead><tbody>
@forelse($rows as $r)
<tr><td>{{ $r['layer']->material?->sku }}<br>{{ $r['layer']->material?->name }}</td><td>{{ $r['receipt']?:'Unlinked source' }}</td>
<td>{{ $r['layer']->original_cost===null?'Unvalued':($r['layer']->currency_code??'Unknown') }}</td>
<td>{{ $r['layer']->remaining_quantity }}</td>
<td>@if($r['sample'])<a href="{{ route('laboratory.samples.show',$r['sample']->id) }}">{{ $r['sample']->number }}</a><br>{{ $r['sample']->status }}@else Not registered @endif</td>
<td>@if($r['manual_hold'])
<strong style="color:#a23131">Manual hold #{{ $r['manual_hold']->id }}</strong>
<div style="max-width:220px">{{ $r['manual_hold']->reference }}</div>
@else<span class="muted">No manual hold</span>@endif</td>
<td>@if($r['held'])<strong style="color:#a23131">HELD — cannot issue</strong>@else <strong style="color:#168257">QC clear / no active restriction</strong>@endif</td>
<td>
@canany(['laboratory.update','administration.update'])
@if(!$r['manual_hold'])
<details><summary>Place QC hold</summary>
<form method="POST" action="{{ route('inventory.quality-holds.hold',$r['layer']) }}" style="display:grid;gap:6px;min-width:185px">
@csrf<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<input name="reference" required minlength="3" maxlength="180" placeholder="Incident / lab reference" aria-label="Hold reference">
<textarea name="reason" required minlength="10" maxlength="3000" placeholder="Why this lot must be quarantined" aria-label="Hold reason"></textarea>
<button class="btn" type="submit">Hold this lot</button>
</form></details>
@else
<details><summary>Release manual hold</summary>
<form method="POST" action="{{ route('inventory.quality-holds.release',$r['manual_hold']->id) }}" style="display:grid;gap:6px;min-width:185px">
@csrf
<input name="reference" required minlength="3" maxlength="180" placeholder="Release authorization" aria-label="Release reference">
<textarea name="reason" required minlength="10" maxlength="3000" placeholder="Evidence and release decision" aria-label="Release reason"></textarea>
<button class="btn btn-light" type="submit">Record independent release</button>
</form></details>
@endif
@endcanany
</td>
</tr>
@empty<tr><td colspan="8">No receipt lots recorded.</td></tr>@endforelse
</tbody></table></div>{{ $layers->links() }}
</section>
<section class="qh-panel" style="margin-top:15px">
<h2>Manual hold and release evidence</h2>
<div class="qh-wrap"><table><thead><tr><th>Date</th><th>FIFO lot</th><th>Material</th><th>Action</th><th>Evidence</th><th>Decision reason</th></tr></thead><tbody>
@forelse($events as $event)
<tr><td>{{ $event->created_at }}</td><td>#{{ $event->inventory_cost_layer_id }}</td>
<td>{{ $event->sku }}</td><td>{{ ucfirst($event->action) }}</td><td>{{ $event->reference }}</td><td>{{ $event->reason }}</td></tr>
@empty<tr><td colspan="6">No documented manual QC interventions.</td></tr>@endforelse
</tbody></table></div>
<p class="muted">Showing the latest 60 recorded interventions. All recorded holds and releases remain associated with their original incoming FIFO lot.</p>
</section>
@endsection
