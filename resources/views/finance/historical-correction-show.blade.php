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
<strong>Immutable source evidence:</strong> The original historical document is never edited from this screen. A reviewer different from the requester must approve a correction. Verified original-source corrections have separate authorization and posting controls: missing invoiced COGS, physically counted unconsumed opening FIFO stock, independently supported supplier short-credit settlements and previously capitalized non-sale dispatch expenses. Unsupported historic values, customer sales without invoices and unresolved cases remain investigation-only.
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
@if($case->case_type==='supplier_return_unreconciled')
<section class="hfcs-card">
<h2>Original supplier physical return and credit discrepancy</h2>
<p class="muted">Physical return quantities and original supplier unit-price snapshots are the only source of base purchase value. Freight/customs allocations and external supplier claims are separate. No disputed amount is automatically recognized as a receivable, expense or payable reduction.</p>
<div style="overflow-x:auto"><table style="width:100%;min-width:650px">
<thead><tr><th>Original GRN line</th><th>Returned quantity</th><th>Unit purchase price</th><th>Original base-credit value</th></tr></thead>
<tbody>
@forelse($supplierReturnLines as $line)
<tr><td>#{{ $line->goods_receipt_line_id }}</td>
<td>{{ $line->quantity }}</td><td>{{ $line->unit_price_snapshot??'Unverified' }}</td>
<td>{{ $line->supplier_credit_value_snapshot??'Unverified' }} {{ $supplierCreditCurrency }}</td></tr>
@empty<tr><td colspan="4">Original supplier return lacks line evidence.</td></tr>@endforelse
</tbody></table></div>
@if($supplierDispute)
<h3>Independently reviewed short credit</h3>
<div class="hfcs-grid">
<div><small>Source-priced returned base value</small><div>{{ $supplierDispute->source_base_return_value }} {{ $supplierCreditCurrency }}</div></div>
<div><small>Supplier-offered credit</small><div>{{ $supplierDispute->supplier_offered_credit }} {{ $supplierCreditCurrency }}</div></div>
<div><small>Unresolved original-currency difference</small><div><strong>{{ $supplierDispute->unresolved_difference }} {{ $supplierCreditCurrency }}</strong></div></div>
<div><small>Evidence document</small><div>{{ $supplierDispute->supplier_document_reference }}</div></div>
<div><small>Proposed treatment</small><div>{{ str_replace('_',' ',ucfirst($supplierDispute->requested_treatment)) }}</div></div>
<div><small>Independent reviewer</small><div>#{{ $supplierDispute->reviewed_by }}</div></div>
</div>
<p style="white-space:pre-wrap">{{ $supplierDispute->source_evidence }}</p>
<p><strong>Required accounting action:</strong></p><p style="white-space:pre-wrap">{{ $supplierDispute->remaining_approval_action }}</p>
<p class="hfcs-alert">The original return remains awaiting a fully reconciled supplier debit note or a separately approved disputed-value accounting procedure. This document does not book an enforceable supplier claim or expense and does not resolve the financial correction case.</p>
@elseif($case->status==='approved' &&
 (auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
 (int)$case->requested_by!==auth()->id())
<h3>Document independently reviewed supplier short-credit evidence</h3>
<p class="hfcs-alert">A supplier credit below the verified returned base purchase value leaves a difference requiring approved receivable, loss or offset treatment. This form records the original supplier offer only; it does not write GL or customer/supplier books. The normal supplier debit-note service continues requiring a fully reconciled base-value credit.</p>
<form method="POST" action="{{ route('finance.historical-corrections.supplier-credit-dispute',$case->id) }}" class="hfcs-form">
@csrf
<label>Supplier offered original-currency credit ({{ $supplierCreditCurrency }})
<input name="supplier_offered_credit" type="text" inputmode="decimal" required value="{{ old('supplier_offered_credit','0') }}" placeholder="0.0000"></label>
<label>Proposed next accounting review
<select name="requested_treatment" required>
<option value="">Select independently evidenced follow-up</option>
<option value="supplier_claim_pending">Supplier reimbursement/claim review required</option>
<option value="potential_nonrecoverable_loss">Potential nonrecoverable loss requiring approval</option>
<option value="unresolved">Supplier evidence remains incomplete</option>
</select></label>
<label>Supplier's actual credit document / correspondence reference
<input name="supplier_document_reference" required minlength="4" maxlength="180"></label>
<label>Independent supporting source evidence
<textarea name="source_evidence" required minlength="30" maxlength="5000" rows="4"
 placeholder="Identify original GRN, returned physical quantities, actual supplier offered credit and signed supporting correspondence."></textarea></label>
<label>Outstanding payable / receivable / loss approval action
<textarea name="remaining_approval_action" required minlength="30" maxlength="5000" rows="4"
 placeholder="Describe which source-backed accounting documents and authorization must still be obtained. Do not guess recoverability."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_review_only" value="1" required style="width:auto">
This records a disputed supplier proposal only; it does not recognize a claim, expense, inventory asset or payable credit.
</label>
<button class="btn" type="submit" onclick="return confirm('Document this source-priced supplier dispute without posting any financial transaction?')">Record supplier short-credit review</button>
</form>
@endif
@if($shortCreditSettlement)
<h3>Supplier short-credit settlement and independent GL recognition</h3>
<div class="hfcs-grid">
<div><small>Settlement status</small><div><strong>{{ ucfirst($shortCreditSettlement->status) }}</strong></div></div>
<div><small>Original matched payable</small><div>#{{ $shortCreditSettlement->trade_document_id }}</div></div>
<div><small>Original inventory reversal</small><div>{{ $shortCreditSettlement->source_base_return_value }} {{ $supplierCreditCurrency }}</div></div>
<div><small>Accepted supplier payable reduction</small><div>{{ $shortCreditSettlement->supplier_offered_credit }} {{ $supplierCreditCurrency }}</div></div>
<div><small>Authorized claim / loss</small><div>{{ $shortCreditSettlement->unresolved_difference }} {{ $supplierCreditCurrency }} ({{ str_replace('_',' ',$shortCreditSettlement->treatment) }})</div></div>
<div><small>Posted journal</small><div>{{ $shortCreditSettlement->journal_entry_id ? '#'.$shortCreditSettlement->journal_entry_id : 'Not posted' }}</div></div>
</div>
<p><strong>Signed recognition source:</strong></p><p style="white-space:pre-wrap">{{ $shortCreditSettlement->recognition_evidence }}</p>
@if($shortCreditSettlement->status==='submitted' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$shortCreditSettlement->submitted_by!==auth()->id() &&
(int)$case->requested_by!==auth()->id() &&
(int)$supplierDispute->reviewed_by!==auth()->id())
<form method="POST" action="{{ route('finance.historical-corrections.short-credit-approve',$case->id) }}" class="hfcs-form">
@csrf
<h3>Independent financial recognition decision</h3>
<label>Decision
<select name="decision" required>
<option value="approve">Approve source-priced accounting treatment</option>
<option value="reject">Reject unsupported accounting evidence</option>
</select></label>
<label>Signed independent authorization reference
<input name="approval_reference" minlength="4" maxlength="180" required></label>
<label>Review of original supplier claim or nonrecoverable loss
<textarea name="approval_notes" rows="5" minlength="40" maxlength="5000" required
placeholder="Identify original supplier invoice, value, legal claim basis or approved loss write-off, matching payable and GL account."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_independent_settlement" value="1" required style="width:auto">
I independently verified original-currency source prices, receivable/loss authority and absence of a prior return-cost journal.
</label>
<button type="submit" class="btn">Record independent settlement decision</button>
</form>
@endif
@if($shortCreditSettlement->status==='approved' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$shortCreditSettlement->submitted_by!==auth()->id() &&
(int)$shortCreditSettlement->approved_by!==auth()->id() &&
(int)$case->requested_by!==auth()->id())
<form method="POST" action="{{ route('finance.historical-corrections.short-credit-post',$case->id) }}" class="hfcs-form">
@csrf
<p class="hfcs-alert">An approved original-currency journal will debit the actual accepted supplier payable credit, debit the approved claim asset or loss expense for the remaining difference, and credit the original matched inventory account for the full physical return base price. Original warehouse quantities do not change.</p>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_post_short_credit" value="1" required style="width:auto">
I have independently checked the approved journal accounts, posting period and original source documents.
</label>
<button type="submit" class="btn" onclick="return confirm('Post the independently approved original-currency short-credit journal?')">Post approved supplier correction</button>
</form>
@endif
@elseif($supplierDispute && $case->status==='approved' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$case->requested_by!==auth()->id() &&
(int)$supplierDispute->reviewed_by!==auth()->id() &&
$supplierDispute->requested_treatment!=='unresolved')
<h3>Propose independent original-currency supplier short-credit settlement</h3>
<p class="hfcs-alert">A documented, nonrecoverable expense or legally supported supplier claim may be recognized only after a second independent financial approval. A proposal never posts a journal, changes inventory quantities or invents a supplier credit.</p>
<form method="POST" action="{{ route('finance.historical-corrections.short-credit-submit',$case->id) }}" class="hfcs-form">
@csrf
<label>Source-matched posted supplier payable
<select name="trade_document_id" required>
<option value="">Select original supplier invoice</option>
@foreach($shortCreditPayables as $payable)
<option value="{{ $payable->id }}" @selected(old('trade_document_id')==$payable->id)>{{ $payable->number }} — {{ $payable->supplier_invoice_number }} ({{ $payable->amount }} {{ $supplierCreditCurrency }})</option>
@endforeach
</select></label>
<label>Authorized treatment of original-currency difference
<select name="treatment" required>
@if($supplierDispute->requested_treatment==='supplier_claim_pending')
<option value="recognized_supplier_claim">Documented recognized supplier claim (asset)</option>
@else
<option value="approved_nonrecoverable_loss">Authorized nonrecoverable supplier loss (expense)</option>
@endif
</select></label>
<label>Approved difference ledger
<select name="difference_ledger_id" required>
<option value="">Select approved GL account</option>
@foreach($shortCreditDifferenceLedgers as $ledger)
<option value="{{ $ledger->id }}" @selected(old('difference_ledger_id')==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>
@endforeach
</select></label>
<label>Posting date in an OPEN accounting period
<input type="date" name="posting_date" min="{{ $case->source_date }}" max="{{ now()->toDateString() }}" value="{{ old('posting_date',now()->toDateString()) }}" required></label>
<label>Signed supplier-credit settlement / loss approval document
<input name="settlement_reference" minlength="4" maxlength="180" value="{{ old('settlement_reference') }}" required></label>
<label>Source-price and legally supported recognition explanation
<textarea name="recognition_evidence" rows="5" minlength="50" maxlength="5000" required
placeholder="Document original supplier invoice, GRN quantities, accepted credit, independent claim recovery evidence or authorized loss write-off.">{{ old('recognition_evidence') }}</textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_signed_recognition" value="1" required style="width:auto">
The original invoice, return, claim or write-off authorization and absence of earlier GL recognition have been reviewed.
</label>
<button type="submit" class="btn">Submit source-matched financial correction</button>
</form>
@endif
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
<p class="hfcs-alert">Business purpose has been documented. Only previously capitalized, source-priced non-sale dispatches can proceed to independently authorized expense recognition. Customer sales and unresolved purposes remain nonposting; no fabricated invoice or revenue is permitted.</p>
@if($dispatchCostSettlement)
<h3>Historical non-sale expense settlement</h3>
<div class="hfcs-grid">
<div><small>Approval state</small><div><strong>{{ ucfirst($dispatchCostSettlement->status) }}</strong></div></div>
<div><small>Recognized purpose</small><div>{{ str_replace('_',' ',$dispatchCostSettlement->business_purpose) }}</div></div>
<div><small>Posting date</small><div>{{ $dispatchCostSettlement->posting_date }}</div></div>
<div><small>Source authorization</small><div>{{ $dispatchCostSettlement->source_authorization_reference }}</div></div>
<div><small>Finance approver</small><div>{{ $dispatchCostSettlement->approved_by ? '#'.$dispatchCostSettlement->approved_by : 'Pending' }}</div></div>
</div>
<p><strong>Recognized purpose and original costing evidence:</strong></p>
<p style="white-space:pre-wrap">{{ $dispatchCostSettlement->expense_recognition_evidence }}</p>
<div style="overflow-x:auto">
<table style="width:100%;min-width:620px">
<thead><tr><th>Original dispatch line</th><th>Package quantity</th><th>Verified cost</th><th>Expense ledger</th><th>Posting journal</th></tr></thead>
<tbody>
@foreach($dispatchCostSettlementLines as $record)
<tr>
<td>#{{ $record->finished_good_dispatch_line_id }}</td>
<td>{{ number_format($record->quantity_packages) }}</td>
<td>{{ $record->source_cost_amount }} {{ $record->currency_code }}</td>
<td>#{{ $record->expense_ledger_id }}</td>
<td>{{ $record->journal_number??'Not posted' }} {{ $record->journal_status ? '('.$record->journal_status.')' : '' }}</td>
</tr>
@endforeach
</tbody></table></div>
@if($dispatchCostSettlement->status==='submitted' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$dispatchCostSettlement->submitted_by!==auth()->id() &&
(int)$dispatchDisposition->reviewed_by!==auth()->id() &&
(int)$case->requested_by!==auth()->id())
<h3>Independent original-currency expense approval</h3>
<form method="POST" action="{{ route('finance.historical-corrections.dispatch-cost-approve',$case->id) }}" class="hfcs-form">
@csrf
<label>Decision<select name="decision" required>
<option value="approve">Approve original-capitalized non-sale expense treatment</option>
<option value="reject">Reject unsupported or unmatched cost accounting</option>
</select></label>
<label>Signed approval document
<input name="approval_reference" required minlength="4" maxlength="180"></label>
<label>Original inventory and expense-account recognition review
<textarea name="approval_notes" rows="5" minlength="40" maxlength="5000" required
placeholder="Review each source batch cost journal, accepted quantity, original currency, purpose-specific expense account and signed recipient/loss evidence."></textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_independent_cost" value="1" required style="width:auto">
I independently verified the historical purpose and original manufacturing GL source before authorizing these expenses.
</label>
<button class="btn" type="submit">Record independent financial decision</button>
</form>
@endif
@if($dispatchCostSettlement->status==='approved' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$dispatchCostSettlement->submitted_by!==auth()->id() &&
(int)$dispatchCostSettlement->approved_by!==auth()->id() &&
(int)$dispatchDisposition->reviewed_by!==auth()->id() &&
(int)$case->requested_by!==auth()->id())
<h3>Execute approved non-sale accounting</h3>
<form method="POST" action="{{ route('finance.historical-corrections.dispatch-cost-post',$case->id) }}" class="hfcs-form">
@csrf
<p class="hfcs-alert">Final execution creates an original-currency debit to the independently approved sampling/damage/internal-use expense and credit to the posted finished-goods asset for EACH verified physical dispatch line. Any one invalid line blocks all journals in this transaction. It cannot create sales, receivables or additional physical dispatches.</p>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_original_cost_posting" value="1" required style="width:auto">
I checked each source GL cost, independent approval, posting date and financial period.
</label>
<button class="btn" type="submit"
onclick="return confirm('Post all approved original-currency non-sale expense journals for this physical dispatch?')">
Post approved historical dispatch expense</button>
</form>
@endif
@elseif($case->status==='approved' &&
in_array($dispatchDisposition->business_purpose,['promotional_sample','production_loss','internal_consumption'],true) &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$case->requested_by!==auth()->id() &&
(int)$dispatchDisposition->reviewed_by!==auth()->id())
<h3>Propose verified original-cost non-sale financial recognition</h3>
<p class="hfcs-alert">Every physical line must already have original manufacturing WIP and finished-inventory journal capitalization dated on or before the physical dispatch. Reports showing only estimates do not qualify. Each original-currency expense must be explicitly approved before posting; source and warehouse quantities remain unchanged.</p>
<form method="POST" action="{{ route('finance.historical-corrections.dispatch-cost-submit',$case->id) }}" class="hfcs-form">
@csrf
@foreach($dispatchEvidence as $detail)
<label>Dispatch line #{{ $detail->id }} — batch {{ $detail->batch_number }} ({{ number_format($detail->quantity_packages) }} packages)
@if(!$detail->total_amount)
<span class="muted">Source capitalization unavailable; finish historical source reconciliation first.</span>
@else
<span class="muted">Original capitalized batch {{ $detail->total_amount }} {{ $detail->original_cost_currency }}, {{ $detail->accepted_packages }} accepted packages. The server determines the exact expense portion.</span>
@endif
<select name="expense_ledgers[{{ $detail->id }}]" required>
<option value="">Select authorized {{ str_replace('_',' ',$dispatchDisposition->business_purpose) }} expense GL</option>
@foreach($dispatchExpenseLedgers as $ledger)
<option value="{{ $ledger->id }}" @selected(old('expense_ledgers.'.$detail->id)==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>
@endforeach
</select></label>
@endforeach
<label>Journal date in an open accounting period
<input type="date" name="posting_date" min="{{ $case->source_date }}"
max="{{ now()->toDateString() }}" value="{{ old('posting_date',now()->toDateString()) }}" required></label>
<label>Signed source expense and recipient/loss authorization reference
<input name="source_authorization_reference" value="{{ old('source_authorization_reference') }}"
minlength="4" maxlength="180" required></label>
<label>Original-currency expense recognition explanation and source documents
<textarea name="expense_recognition_evidence" rows="5" minlength="50" maxlength="5000" required
placeholder="Document original physical dispatch, purpose, authorized recipient or loss, original manufacturing cost GL, value, currency, selected expense account and any previous GL review.">{{ old('expense_recognition_evidence') }}</textarea></label>
<label style="display:flex;align-items:center;gap:8px">
<input type="checkbox" name="confirm_original_non_sale_cost" value="1" required style="width:auto">
All original batch costs, GL capitalization and non-sale evidence are independently documented; no earlier expense or invoice already recognized this dispatch.
</label>
<button class="btn" type="submit">Submit original-cost expense for separate approval</button>
</form>
@endif
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
