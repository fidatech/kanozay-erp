@extends('layouts.kanozay')
@section('content')
<style>
.hfcs{max-width:1100px;margin:0 auto;display:grid;gap:16px}
.hfcs-card{padding:20px;border:1px solid #dce5ee;background:#fff;border-radius:12px}
.hfcs-alert{background:#fff8eb;border:1px solid #efd2a7;padding:13px;border-radius:9px;line-height:1.7}
.hfcs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.hfcs-grid>div{background:#f6f9fc;border-radius:8px;padding:11px}
.hfcs-form{display:grid;gap:12px;max-width:750px}
.hfcs-form label{display:grid;gap:5px}
.hfcs-form input,.hfcs-form select,.hfcs-form textarea{padding:10px;border:1px solid #d5e0ea;border-radius:8px;width:100%}
.hfcs-event{padding:12px;border-bottom:1px solid #e5ecf4}
</style>
<div class="hfcs">
<div class="heading"><div>
<h1>{{ $case->case_number }} — {{ $types[$case->case_type]??$case->case_type }}</h1>
<p>Historical correction source review. Current state: <strong>{{ ucfirst($case->status) }}</strong>.</p>
</div><div class="actions"><a class="btn btn-light" href="{{ route('finance.historical-corrections.index') }}">Correction center</a>
@if($case->case_type==='invoice_missing_cogs')
<a class="btn btn-light" href="{{ route('sales.invoices.show',$case->source_id) }}">Original customer invoice</a>
@endif</div></div>
<div class="hfcs-alert">
<strong>Immutable source evidence:</strong> The original historical document is never edited from this screen. A reviewer different from the requester must approve a correction. Only an invoice already backed by verified finished-goods capitalization can create missing COGS journals. Other exception types are investigation-only and cannot silently create inventory value or GL balances.
</div>
<section class="hfcs-card">
<h2>Original source and submitted discrepancy</h2>
<div class="hfcs-grid">
<div><small>Source document</small><div><strong>{{ $case->source_reference }}</strong> · #{{ $case->source_id }}</div></div>
<div><small>Source date</small><div>{{ $case->source_date }}</div></div>
<div><small>Original currency</small><div>{{ $case->source_currency_id??'Not established / source-independent' }}</div></div>
<div><small>Requester</small><div>Employee #{{ $case->requested_by }}</div></div>
<div><small>State</small><div><strong>{{ ucfirst($case->status) }}</strong></div></div>
<div><small>Document fingerprint</small><div style="font:11px ui-monospace,monospace;overflow-wrap:anywhere">{{ $case->source_fingerprint }}</div></div>
</div>
<h3>Identified discrepancy</h3><p style="white-space:pre-wrap">{{ $case->identified_discrepancy }}</p>
<h3>Source evidence</h3><p style="white-space:pre-wrap">{{ $case->source_evidence }}</p>
@if($case->reviewed_by)
<p><strong>Review:</strong> {{ $case->approval_reference }} · Reviewer #{{ $case->reviewed_by }}</p>
<p style="white-space:pre-wrap">{{ $case->review_notes }}</p>
@endif
@if($case->executed_at)
<p><strong>Execution:</strong> {{ $case->resolution_reference }} · {{ $case->executed_at }} · {{ $case->posted_cogs_lines }} new COGS source journals</p>
<p style="white-space:pre-wrap">{{ $case->resolution_notes }}</p>
@endif
</section>
@if($case->status==='submitted' &&
 (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
 (int)$case->requested_by!==auth()->id())
<section class="hfcs-card">
<h2>Independent finance review</h2>
<p class="muted">The approval only authorizes the evidence review. It does not guarantee that historical values can be recovered or that any GL posting will succeed.</p>
<form method="POST" action="{{ route('finance.historical-corrections.review',$case->id) }}" class="hfcs-form">
@csrf
<label>Decision<select name="decision" required><option value="">Choose review decision</option>
<option value="approve">Approve the documented case for source reconciliation</option>
<option value="reject">Reject insufficient or invalid evidence</option></select></label>
<label>Signed approval or rejection reference
<input name="approval_reference" required minlength="4" maxlength="180"></label>
<label>Independent review notes
<textarea name="review_notes" required minlength="20" maxlength="4000" rows="4" placeholder="Confirm physical dispatch, original cost journals, currency and date, or describe missing documents."></textarea></label>
<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="confirm_independent_review" value="1" required style="width:auto">
I am not the requester and independently checked the original source documentation.</label>
<button class="btn" type="submit" onclick="return confirm('Record this independent finance decision? No original stock or GL value will change.')">Save independent finance review</button>
</form></section>
@endif
@if($case->status==='approved' && $case->case_type==='invoice_missing_cogs' &&
 (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
 (int)$case->requested_by!==auth()->id())
<section class="hfcs-card">
<h2>Post verified missing invoice COGS journals</h2>
<div class="hfcs-alert">This is the only automated execution supported by historical correction cases. The existing cost posting service rechecks batch inventory capitalization, actual dispatch and invoice quantity, original currency, valid GL accounts and an open accounting period. If any line cannot be reconciled, the transaction rolls back. Approved cases for other document types remain investigation records.</div>
<form method="POST" action="{{ route('finance.historical-corrections.execute',$case->id) }}" class="hfcs-form" style="margin-top:14px">
@csrf
<label>Signed resolution reference
<input name="resolution_reference" required minlength="4" maxlength="180"></label>
<label>Original valuation reconciliation evidence
<textarea name="resolution_notes" required minlength="20" maxlength="4000" rows="4" placeholder="Identify the original posted batch cost, COGS dates, warehouse dispatch and cost-currency evidence."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_cogs_journal" required value="1" style="width:auto">
I verified the existing original-currency batch cost and acknowledge this action may post previously missing COGS journals.
</label>
<button class="btn" type="submit" onclick="return confirm('Post missing original-currency COGS journals for this independently approved case?')">Execute source-verified correction</button>
</form></section>
@elseif($case->status==='approved' && $case->case_type!=='invoice_missing_cogs')
<div class="hfcs-alert"><strong>Approved for investigation only.</strong>
The ERP does not yet have an authorized automated correction for this legacy stock, source lot, supplier credit or capitalization type. Preserve this case as an unresolved exception until a dedicated historical posting and reconciliation service exists. Do not create an arbitrary journal to mark it resolved.</div>
@endif
<section class="hfcs-card">
<h2>Permanent case event history</h2>
@forelse($events as $event)
<div class="hfcs-event">
<strong>{{ ucfirst($event->action) }}</strong>
· {{ $event->created_at }} · Employee #{{ $event->actor_id }}
@if($event->approval_reference) · {{ $event->approval_reference }}@endif
<div class="muted">{{ $event->notes }}</div>
</div>
@empty<p class="muted">No review events found for this case.</p>@endforelse
</section>
</div>
@endsection
