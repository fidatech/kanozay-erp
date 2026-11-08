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
@if($case->case_type==='opening_fifo_unvalued' && $openingPosting)
<section class="hfcs-card">
<h2>Original opening-stock valuation journal</h2>
<div class="hfcs-grid">
<div><small>Posted journal</small><div><strong>{{ $openingPosting->journal_number }}</strong></div></div>
<div><small>Posting date</small><div>{{ $openingPosting->posting_date }}</div></div>
<div><small>Original FIFO cost</small><div>{{ $openingPosting->original_cost }}</div></div>
<div><small>Original currency ID</small><div>{{ $openingPosting->currency_id }}</div></div>
<div><small>Inventory asset ledger</small><div>#{{ $openingPosting->inventory_ledger_id }}</div></div>
<div><small>Opening equity ledger</small><div>#{{ $openingPosting->opening_equity_ledger_id }}</div></div>
</div>
<p class="muted">The source physical stock receipt is unchanged. Original opening cost was recognized in the separately dated, independently approved asset/equity journal. The posting status must remain reconciled to the original financial statements.</p>
</section>
@endif
@if($case->status==='approved' && $case->case_type==='opening_fifo_unvalued' &&
 (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
 (int)$case->requested_by!==auth()->id())
<section class="hfcs-card">
<h2>Recognize unconsumed original opening inventory</h2>
<div class="hfcs-alert"><strong>Limited to original physical opening movements with zero FIFO consumption.</strong>
All original units must remain on hand with verified active warehouse locations. The manufacturing inventory asset ledger must already be approved for the source currency. This posts an asset debit and a signed opening-equity credit on the chosen open accounting date. It will not create new physical stock, alter earlier cost consumptions, or assume a missing original purchase value. If finance previously posted the opening cost manually, do not use this action.</div>
<form method="POST" action="{{ route('finance.historical-corrections.opening-post',$case->id) }}" class="hfcs-form" style="margin-top:14px">
@csrf
<label>Source accounting currency
<select name="currency_id" required>
<option value="">Select independently approved inventory policy currency</option>
@foreach($currencies as $currency)
<option value="{{ $currency->id }}" @selected(old('currency_id')==$currency->id)>{{ $currency->code }}</option>
@endforeach
</select></label>
<label>Total original lot cost in that currency
<input type="text" name="original_cost" inputmode="decimal" required
 value="{{ old('original_cost') }}" placeholder="0.0000"></label>
<label>Opening-equity posting account
<select name="opening_equity_ledger_id" required>
<option value="">Choose active opening-equity account</option>
@foreach($openingEquityLedgers as $account)
<option value="{{ $account->id }}" @selected(old('opening_equity_ledger_id')==$account->id)>{{ $account->code }} · {{ $account->name }}</option>
@endforeach
</select></label>
<label>Posting date in an OPEN accounting period
<input name="posting_date" type="date" value="{{ old('posting_date',now()->toDateString()) }}"
 max="{{ now()->toDateString() }}" min="{{ $case->source_date }}" required></label>
<label>Signed opening-valuation evidence
<input name="evidence_reference" required minlength="4" maxlength="180"
 value="{{ old('evidence_reference') }}" placeholder="Physical count and original cost authorization"></label>
<label>Finance reconciliation and opening-equity evidence
<textarea name="reconciliation_notes" rows="5" minlength="40" maxlength="5000" required
 placeholder="Identify physical count, original source cost documentation, original currency, opening equity account and evidence no earlier GL inventory valuation exists.">{{ old('reconciliation_notes') }}</textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_physical_source" value="1" required style="width:auto">
The source lot is physically counted, entirely unconsumed and fully assigned to verified warehouses.
</label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_no_prior_gl" value="1" required style="width:auto">
Finance verified no earlier inventory GL entry has recognized this exact physical opening lot; the chosen equity account and original value are documented.
</label>
<button class="btn" type="submit"
 onclick="return confirm('Post the independently evidenced original FIFO cost as a single asset/equity journal without changing physical stock?')">
Post verified opening-stock valuation</button>
</form>
</section>
@endif
@if($case->case_type==='standalone_dispatch')
<section class="hfcs-card">
<h2>Recorded physical dispatch and source-batch cost evidence</h2>
<p class="muted">These are factual quantities and original batch amounts, not an approved price for a customer sale, a sample or a loss. The original dispatch remains immutable and no stock is added or removed here.</p>
<div style="overflow-x:auto"><table style="width:100%;min-width:780px">
<thead><tr><th>Source batch</th><th>Dispatched packages</th><th>Warehouse</th><th>Accepted batch packages</th><th>Original batch capitalized value</th><th>Original costing GL</th></tr></thead><tbody>
@forelse($dispatchEvidence as $detail)
<tr><td>{{ $detail->batch_number }} (#{{ $detail->production_batch_id }})</td>
<td>{{ number_format($detail->quantity_packages) }}</td>
<td>#{{ $detail->company_warehouse_id??'Unassigned' }}</td>
<td>{{ number_format((int)$detail->accepted_packages) }}</td>
<td>{{ $detail->total_amount??'No verified capitalization' }} {{ $detail->original_cost_currency }}</td>
<td>@if($detail->accounting_date)
{{ $detail->accounting_date }} · {{ $detail->capitalization_journal_status??'Journal absent' }}
@else Source capitalization missing @endif</td>
</tr>
@empty<tr><td colspan="6">No verifiable physical dispatch source lines found.</td></tr>@endforelse
</tbody></table></div>
@if($dispatchDisposition)
<h3>Independent business-purpose review</h3>
<div class="hfcs-grid">
<div><small>Disposition</small><div><strong>{{ str_replace('_',' ',ucfirst($dispatchDisposition->business_purpose)) }}</strong></div></div>
<div><small>Evidence document</small><div>{{ $dispatchDisposition->supporting_document_reference }}</div></div>
<div><small>Reviewer</small><div>#{{ $dispatchDisposition->reviewed_by }}</div></div>
</div>
<p style="white-space:pre-wrap">{{ $dispatchDisposition->source_evidence }}</p>
<p><strong>Remaining financial action:</strong></p>
<p style="white-space:pre-wrap">{{ $dispatchDisposition->remaining_financial_action }}</p>
<p class="hfcs-alert">Disposition is recorded but the case remains open. Financial posting still needs an independently source-valued, purpose-specific correction. No customer receivable, revenue, COGS or expense entry was created.</p>
@elseif($case->status==='approved' &&
 (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
 (int)$case->requested_by!==auth()->id())
<h3>Classify original warehouse-only dispatch purpose</h3>
<p class="hfcs-alert">A missing customer invoice must not be invented from a warehouse issue. Review the original signed reason. Samples, damaged output and internal consumption may require different expense accounts, while sales require separately valid customer and original revenue documents. This form records the conclusion only; it cannot post GL entries.</p>
<form method="POST" action="{{ route('finance.historical-corrections.dispatch-purpose',$case->id) }}" class="hfcs-form">
@csrf
<label>Business purpose
<select name="business_purpose" required>
<option value="">Choose evidenced business disposition</option>
<option value="customer_sale">Customer sale requiring independent invoice/COGS reconciliation</option>
<option value="promotional_sample">Promotional or product sampling expense</option>
<option value="production_loss">Damage or nonrecoverable inventory loss</option>
<option value="internal_consumption">Approved internal-use consumption</option>
<option value="unresolved">Purpose still unverified</option>
</select></label>
<label>Original supporting document reference
<input name="supporting_document_reference" required minlength="4" maxlength="180"></label>
<label>Independent source evidence
<textarea name="source_evidence" rows="4" required minlength="30" maxlength="5000"
 placeholder="State original dispatch source, authorized customer/recipient, purpose, quantity, date and signed evidence."></textarea></label>
<label>Outstanding original-currency accounting action
<textarea name="remaining_financial_action" rows="4" required minlength="30" maxlength="5000"
 placeholder="Describe which source GL and operational documents still need to be created or reconciled. No estimated amounts."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_no_gl_posting" value="1" required style="width:auto">
I understand this review neither approves an accounting value nor records an invoice, GL journal or stock movement.
</label>
<button class="btn" type="submit"
 onclick="return confirm('Record this source-linked physical dispatch classification without posting any financial or warehouse movement?')">Record independent disposition</button>
</form>
@endif
</section>
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
@elseif($case->status==='approved' && !in_array($case->case_type,['invoice_missing_cogs','opening_fifo_unvalued'],true))
<div class="hfcs-alert"><strong>Approved for investigation only.</strong>
The ERP does not yet have an authorized automated correction for this legacy dispatch, consumed source lot, supplier credit or uncapitalized production batch type. An unconsumed, source-verified physical opening FIFO lot has a separate restricted valuation path above. Preserve this case as an unresolved exception until a dedicated historical posting and reconciliation service exists. Do not create an arbitrary journal to mark it resolved.</div>
@endif
@if(in_array($case->status,['submitted','approved'],true) &&
    (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
    (($case->status==='submitted' && (int)$case->requested_by===auth()->id()) ||
     ($case->status==='approved' && (int)$case->reviewed_by===auth()->id())))
<section class="hfcs-card">
<h2>{{ $case->status==='approved'?'Invalidate an unexecuted approval':'Withdraw an unreviewed request' }}</h2>
<p class="muted">Use this only when original source records have changed or the request should be replaced. Withdrawal preserves the previous review events and creates no stock or GL entry.</p>
<form method="POST" action="{{ route('finance.historical-corrections.withdraw',$case->id) }}" class="hfcs-form">
@csrf
<label>Documented reason
<textarea name="reason" rows="3" minlength="20" maxlength="3000" required placeholder="Explain changed source records or why a replacement case is required."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_withdrawal" value="1" required style="width:auto">
I understand that withdrawal cannot reverse a journal and only affects this unexecuted correction case.
</label>
<button class="btn btn-light" type="submit"
 onclick="return confirm('Withdraw this unexecuted case and preserve its complete historical record?')">Withdraw unresolved case</button>
</form>
</section>
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
