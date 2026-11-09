@extends('layouts.kanozay')
@section('content')
<style>
.lfr-panel{background:var(--card-bg,#fff);border:1px solid #dfe8f3;border-radius:12px;padding:20px;margin:16px 0}
.lfr-panel h2{font-size:17px;margin:0 0 11px}
.lfr-muted{font-size:12px;color:#61768b;line-height:1.55}
.lfr-warning{font-size:13px;color:#805220;background:#fffaf1;border:1px solid #ecd7b2;padding:12px;border-radius:9px;margin-bottom:13px}
.lfr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.lfr-grid label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:650}
.lfr-grid input,.lfr-grid select,.lfr-grid textarea{padding:10px;border-radius:8px;border:1px solid #d4e2ed;min-width:0;width:100%;box-sizing:border-box}
.lfr-table{overflow-x:auto}
.lfr-table table{width:100%;min-width:850px;border-collapse:collapse;font-size:12px}
.lfr-table th,.lfr-table td{padding:11px;border-bottom:1px solid #e2eaf2;text-align:left;vertical-align:top}
.lfr-table th{background:#f1f6fb;font-weight:700}
.lfr-review{background:#f5f9ff;border-radius:9px;padding:12px;margin:8px 0}
.lfr-review form{display:grid;grid-template-columns:150px 1fr;gap:9px}
.lfr-review label{display:grid;gap:4px;font-size:12px}
.lfr-review input,.lfr-review textarea,.lfr-review select{padding:9px;width:100%;box-sizing:border-box;border:1px solid #cedfeb;border-radius:7px}
@media(max-width:760px){.lfr-grid,.lfr-review form{grid-template-columns:1fr}.lfr-panel{padding:12px}}
</style>
<div class="heading"><div>
<h1>Historical opening FIFO source review</h1>
<p>Independent warehouse attribution and consumed original FIFO provenance. No inferred values or duplicate physical movements.</p>
</div><div style="display:flex;gap:8px;flex-wrap:wrap">
<a href="{{ route('inventory.warehouse-stock.index') }}" class="btn btn-light">Warehouse stock</a>
<a href="{{ route('inventory.warehouse-valuation.index') }}" class="btn btn-light">Stock valuation</a>
</div></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ $errors->first() }}</p>@endif
<section class="lfr-panel">
<h2>Original source and physical reconciliation</h2>
<p class="lfr-warning">A consumed opening lot cannot be financially valued by guessing its old price. The documentary review below verifies the original opening and each already recorded issue, and preserves them unchanged. Assigning a remaining lot to a warehouse requires a verified physical count plus approval by a different finance/inventory officer. An approved location review only reallocates an existing lot position; it never increases global/warehouse stock or posts a GL journal. Existing unconsumed source-linked opening capitalization requires a separate historical finance correction.</p>
<div class="lfr-table"><table>
<thead><tr><th>Original FIFO lot</th><th>Opening quantity</th><th>Consumed quantity</th><th>Remaining quantity</th><th>Unknown location / unassigned</th><th>Provenance review</th></tr></thead><tbody>
@forelse($layers as $lot)
<tr>
<td><strong>{{ $lot->sku }}</strong><div>{{ $lot->material_name }}</div>
<small>Lot #{{ $lot->id }} · opening receipt #{{ $lot->source_id }} · {{ $lot->received_on }} · registrar #{{ $lot->opening_registrar_id??'Unknown' }}</small></td>
<td>{{ $lot->original_quantity }}</td><td>{{ $lot->consumed_quantity }}</td>
<td>{{ $lot->remaining_quantity }}</td>
<td>{{ $lot->unpositioned_quantity }} unpositioned · {{ $lot->unassigned_quantity }} unassigned</td>
<td>@if((float)$lot->consumed_quantity>0)
<a href="#consumed-{{ $lot->id }}">Review original consumed FIFO</a>
@else <span class="lfr-muted">No consumption; existing unconsumed FIFO correction applies</span>@endif
@if((float)$lot->remaining_quantity>0)
<div><a href="{{ route('inventory.warehouse-stock.index') }}">Submit physically counted remaining-lot location evidence</a></div>
@endif
</td>
</tr>
@empty
<tr><td colspan="6">No unvalued original opening FIFO layers currently found. Existing approved source reviews remain listed below.</td></tr>
@endforelse
</tbody></table></div>
</section>
@if(auth()->user()->can('administration.update') ||
(auth()->user()->can('inventory.update') && auth()->user()->can('finance.update')))
<section class="lfr-panel">
<h2>Submit independent consumption provenance evidence</h2>
<p class="lfr-muted">Only documented original opening FIFO consumptions are eligible. This is an evidence review, not a retrospective valuation or GL correction. A separate reviewer must verify the original issue movement quantities and reference documents.</p>
@foreach($layers->filter(fn($lot)=>(float)$lot->consumed_quantity>0 && $lot->original_cost===null) as $lot)
<details id="consumed-{{ $lot->id }}" style="margin:10px 0;padding:11px;border:1px solid #d9e5f1;border-radius:9px">
<summary style="cursor:pointer;font-weight:650">{{ $lot->sku }} — lot #{{ $lot->id }} · {{ $lot->consumed_quantity }} historically consumed</summary>
<form action="{{ route('inventory.legacy-fifo-reviews.consumed') }}" method="POST" class="lfr-grid" style="margin:13px 0">
@csrf
<input type="hidden" name="inventory_cost_layer_id" value="{{ $lot->id }}">
<label>Signed original issue and warehouse evidence reference
<input type="text" name="source_evidence_reference" minlength="4" maxlength="180" required></label>
<label style="grid-column:span 2">Historical opening receipt, FIFO consumption, source issue reference, cost provenance still required
<textarea name="source_reconciliation_notes" minlength="60" maxlength="5000" rows="4" required placeholder="Identify original material opening receipt, all previously issued FIFO movement IDs, quantities, production/order or adjustment purpose, original invoice/purchase proof still required, and reasons no GL value can currently be recognized."></textarea></label>
<label style="grid-column:span 3;flex-direction:row;align-items:center;gap:8px">
<input type="checkbox" name="confirm_no_finance_posting" value="1" style="width:auto" required>
I understand this only reviews original source provenance; it does not alter inventory, cost consumption or GL.</label>
<div style="grid-column:span 3"><button class="btn" type="submit">Submit consumption evidence for independent review</button></div>
</form>
</details>
@endforeach
</section>
@endif
<section class="lfr-panel">
<h2>Signed source review and warehouse attribution decisions</h2>
<div class="lfr-table"><table>
<thead><tr><th>Review</th><th>Original opening lot</th><th>Source quantity</th><th>Warehouse / requested assignment</th><th>Evidence</th><th>Independent decision</th></tr></thead><tbody>
@forelse($reviews as $review)
<tr>
<td><strong>#{{ $review->id }}</strong><div>{{ str_replace('_',' ',ucwords($review->review_type,'_')) }}</div><small>{{ $review->created_at }}</small></td>
<td>{{ $review->sku }} · lot #{{ $review->inventory_cost_layer_id }}
<div class="lfr-muted">Opening {{ $review->opening_quantity }}, consumed {{ $review->consumed_quantity }}, remaining {{ $review->remaining_quantity }}</div></td>
<td>{{ $review->quantity??'Documentary only' }}</td>
<td>{{ $review->warehouse_name??'No warehouse change' }}
@if($review->inventory_legacy_lot_assignment_id)
<div>Original source lot attribution #{{ $review->inventory_legacy_lot_assignment_id }}</div>
@endif
</td>
<td><strong>{{ $review->source_evidence_reference }}</strong>
<div class="lfr-muted" style="white-space:pre-wrap">{{ $review->source_reconciliation_notes }}</div></td>
<td><strong>{{ ucfirst($review->status) }}</strong>
<div class="lfr-muted">Proposer #{{ $review->requested_by }}
@if($review->reviewed_by) · Reviewer #{{ $review->reviewed_by }}@endif</div>
@if($review->decision_reference)
<div>{{ $review->decision_reference }}</div>
<div class="lfr-muted">{{ $review->decision_notes }}</div>
@endif
@if($review->status==='submitted' &&
(auth()->user()->can('administration.update') ||
(auth()->user()->can('inventory.update') && auth()->user()->can('finance.update'))) &&
(int)$review->requested_by!==auth()->id())
<div class="lfr-review">
<form action="{{ route('inventory.legacy-fifo-reviews.review',$review->id) }}" method="POST">
@csrf
<label>Independent decision<select name="decision" required>
<option value="">Choose decision</option>
<option value="approve">Approve genuine source evidence {{ $review->review_type==='locate_remaining'?'and existing physical attribution':'ONLY (no financial posting)' }}</option>
<option value="reject">Reject missing or stale evidence</option>
</select></label>
<label>Signed reviewer reference<input type="text" name="decision_reference" maxlength="180" minlength="4" required></label>
<label style="grid-column:span 2">Actual physical receipt, outbound FIFO issues, original warehouse count and duplicate location evidence
<textarea name="decision_notes" minlength="60" maxlength="5000" rows="4" required placeholder="Independent review of original FIFO source, count, warehouse attribution, provenance and no invented costs/GL."></textarea></label>
<label style="grid-column:span 2;display:flex;flex-direction:row;align-items:center">
<input type="checkbox" name="confirm_independent_review" value="1" style="width:auto" required>
I independently reviewed unchanged original stock and evidence and did not register the original opening receipt or request this action.</label>
<button class="btn" type="submit" onclick="return confirm('Apply the signed, independently source-verified decision? No inventory quantity or cost may be created.')">Record decision</button>
</form></div>
@endif
</td>
</tr>
@empty<tr><td colspan="6">No independently submitted legacy FIFO provenance reviews.</td></tr>@endforelse
</tbody></table></div>
<div style="margin-top:14px">{{ $reviews->links() }}</div>
</section>

<section class="lfr-panel">
<h2>Source-backed original cost for historical FIFO adjustment write-offs</h2>
<p class="lfr-warning">
A signed provenance review is documentary only until a separate original-cost
proposal is approved. Financial recognition below is restricted to FIFO lots
whose EVERY historical issue is a genuine, original-dated inventory
<em>adjustment_out</em> with matching stock-adjustment source records. Production
materials, supplier returns, unverified original ownership/pricing, missing
source warehouse positions, already-capitalized GL and closed original months
remain blocked. The declared original cost must come from real supporting
opening/purchase evidence, not an estimate. Two separate GL steps recognize
the historical original opening <strong>Dr raw inventory / Cr opening equity</strong>
and each actual original adjustment issue <strong>Dr approved adjustment loss /
Cr raw inventory</strong>. Original material issues and physical stock never
change, and the recognized original remaining FIFO amount must equal the
original cost less all previously expensed adjustment issues.
</p>
@if(auth()->user()->can('finance.update') || auth()->user()->can('administration.update'))
@foreach($sourceCostReviews as $review)
<details class="lfr-review" style="margin-bottom:13px">
<summary style="cursor:pointer;font-weight:700">
Original FIFO #{{ $review->inventory_cost_layer_id }} · {{ $review->sku }}
— signed consumption evidence review #{{ $review->id }}
</summary>
<p class="lfr-muted">
Original opening {{ $review->opening_quantity }};
consumed {{ $review->consumed_quantity }};
remaining {{ $review->remaining_quantity }}.
Provenance requester #{{ $review->requested_by }};
independent reviewer #{{ $review->reviewed_by }}.
The source accounting service rejects any non-adjustment issue on this lot.
</p>
<form action="{{ route('inventory.legacy-fifo-reviews.cost-submit') }}"
method="POST" class="lfr-grid" style="margin:14px 0">
@csrf
<input type="hidden" name="finance_legacy_fifo_source_review_id" value="{{ $review->id }}">
<label>Original factory/purchase currency
<select name="currency_id" required><option value="">Choose documented currency</option>
@foreach($currencies as $currency)
<option value="{{ $currency->id }}" @selected(old('currency_id')==$currency->id)>
{{ $currency->code }}</option>
@endforeach
</select></label>
<label>Authentic ORIGINAL opening lot cost (total, 4 decimal places)
<input name="original_total_cost" inputmode="decimal" type="text"
required placeholder="Source-documented amount, not estimated"
value="{{ old('original_total_cost') }}"></label>
<label>Opening equity / source ownership account
<select name="opening_equity_ledger_id" required><option value="">Choose approved equity source</option>
@foreach($equityLedgers as $account)
<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
@endforeach
</select></label>
<label>Original physical stock-adjustment loss expense
<select name="adjustment_loss_ledger_id" required>
<option value="">Choose independent loss expense ledger (not COGS)</option>
@foreach($expenseLedgers as $account)
<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
@endforeach
</select></label>
<label style="grid-column:span 2">Documented original opening / purchase source-cost evidence reference
<input name="original_cost_evidence_reference" minlength="4" maxlength="180"
required placeholder="Actual opening valuation or genuine purchase/source document ID"></label>
<label style="grid-column:span 3">Detailed independent original price, source ownership, GL, historic adjustment issue and no-duplicate-posting reconciliation
<textarea name="source_cost_reconciliation" rows="5"
minlength="80" maxlength="5000" required
placeholder="Identify real dated original ownership/valuation evidence, physical adjustment issue records and source dates, why every issue is a loss (not WIP/supplier return), original currency, approved ledger, physical remaining stock and proof that the source values have not been separately booked."></textarea></label>
<label style="grid-column:span 3;display:flex;flex-direction:row;align-items:center;gap:9px">
<input name="confirm_evidence_original_cost" type="checkbox" value="1" required style="width:auto">
This amount is evidenced by an authentic source document, not a guessed historic purchase price or exchange rate.</label>
<label style="grid-column:span 3;display:flex;flex-direction:row;align-items:center;gap:9px">
<input name="confirm_no_duplicate_manual_gl" type="checkbox" value="1" required style="width:auto">
I reviewed prior manual journals, original WIP/stock-adjustment accounting and ownership: the original receipt and lost issues were not already financially recognized.</label>
<div style="grid-column:span 3">
<button type="submit" class="btn">Submit original FIFO cost and loss fractions for separate financial approval</button>
</div>
</form>
</details>
@endforeach
@endif
@if($sourceCostReviews->isEmpty())
<p class="lfr-muted">No approved consumed-opening FIFO provenance review is awaiting an original-cost proposal. Source-reviewed production issues must use their own WIP/manufacturing accounting correction.</p>
@endif
<h3 style="margin:18px 0 11px">Independently reviewed and posted original opening/adjustment cost cases</h3>
<div class="lfr-table"><table>
<thead><tr>
<th>Source opening lot</th><th>Financial authorization</th>
<th>Original source cost</th><th>Remaining asset / consumed loss</th>
<th>Independent accounts / evidence</th><th>GL journal and review action</th>
</tr></thead><tbody>
@forelse($historicCostPlans as $plan)
<tr>
<td><strong>#{{ $plan->inventory_cost_layer_id }}</strong>
<div>{{ $plan->sku }}</div>
<div class="lfr-muted">Provenance #{{ $plan->finance_legacy_fifo_source_review_id }}
· Original qty {{ $plan->original_quantity }}
· Remaining {{ $plan->remaining_quantity }}</div>
</td>
<td><strong>{{ ucfirst($plan->status) }}</strong>
<div class="lfr-muted">Proposer #{{ $plan->submitted_by }}
@if($plan->approved_by) · Independent reviewer #{{ $plan->approved_by }}@endif
@if($plan->executed_by) · Final executor #{{ $plan->executed_by }}@endif
</div>
@if($plan->approval_reference)
<div>{{ $plan->approval_reference }}</div>
<div class="lfr-muted" style="white-space:pre-wrap">{{ $plan->approval_notes }}</div>
@endif
</td>
<td><strong>{{ $plan->original_total_cost }} {{ $plan->currency_code }}</strong></td>
<td>Existing original remaining inventory
<strong>{{ $plan->remaining_original_cost }}</strong><br>
Recognized original adjustment-loss cost
<strong>{{ $plan->consumed_original_cost }}</strong></td>
<td>
<div>Inventory GL #{{ $plan->inventory_ledger_id }}</div>
<div>Opening equity GL #{{ $plan->opening_equity_ledger_id }}</div>
<div>Actual loss GL #{{ $plan->adjustment_loss_ledger_id }}</div>
<div><strong>{{ $plan->original_cost_evidence_reference }}</strong></div>
<div class="lfr-muted" style="white-space:pre-wrap">{{ $plan->source_cost_reconciliation }}</div>
</td>
<td>
@if($plan->opening_journal_entry_id)
<strong>{{ $plan->opening_journal_number }}</strong>
<div class="lfr-muted">Opening GL #{{ $plan->opening_journal_entry_id }} {{ $plan->opening_journal_status }}</div>
@else <span class="lfr-muted">No journal yet</span>@endif
<details style="margin:8px 0"><summary>View source adjustment issue allocations</summary>
<div>
@foreach($historicIssueCosts->get($plan->id,collect()) as $costLine)
<div style="padding:7px 0;border-bottom:1px solid #e5edf5">
Original issue #{{ $costLine->inventory_stock_movement_id }}
· {{ $costLine->original_issue_date }}
· {{ $costLine->quantity }} packages
· <strong>{{ $costLine->amount }} {{ $plan->currency_code }}</strong>
<div class="lfr-muted">
{{ $costLine->journal_number??'Awaiting GL' }}
{{ $costLine->journal_status??'' }}
</div></div>
@endforeach
</div></details>
@if($plan->status==='submitted' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$plan->submitted_by!==auth()->id())
<form action="{{ route('inventory.legacy-fifo-reviews.cost-review',$plan->id) }}"
method="POST" class="lfr-review" style="margin:10px 0">
@csrf
<label>Independent finance decision<select name="decision" required>
<option value="">Choose</option><option value="approve">Approve authenticated original cost/GL</option>
<option value="reject">Reject unsupported historical cost</option></select></label>
<label>Independent approval reference
<input name="approval_reference" minlength="4" maxlength="180" required></label>
<label style="grid-column:span 2">Review genuine source price/ownership document, original stock issues, separate loss/equity GL, historic dates and duplicate manual GL risk
<textarea name="approval_notes" rows="5" minlength="80" maxlength="5000" required></textarea></label>
<label style="grid-column:span 2;display:flex;align-items:center;flex-direction:row;gap:8px">
<input name="confirm_independent_cost_review" type="checkbox" value="1" required style="width:auto">
I independently verified this documented original cost and accounting treatment and did not enter the original opening or adjustments.</label>
<button class="btn" type="submit">Record independent cost decision</button>
</form>
@endif
@if($plan->status==='approved' &&
(auth()->user()->can('finance.update') || auth()->user()->can('administration.update')) &&
(int)$plan->submitted_by!==auth()->id() &&
(int)$plan->approved_by!==auth()->id())
<form action="{{ route('inventory.legacy-fifo-reviews.cost-post',$plan->id) }}"
method="POST" class="lfr-review" style="margin:10px 0">
@csrf
<p class="lfr-warning" style="grid-column:span 2">
This executor posts source opening inventory/equity on the original receipt date
and each original stock adjustment expense on its actual issue date. All
affected accounting periods must be OPEN. Every journal and value assignment
commits or rolls back together. Original consumed FIFO source rows and physical
inventory are not rewritten. Production issues remain excluded.
</p>
<label style="grid-column:span 2;display:flex;align-items:center;flex-direction:row;gap:8px">
<input name="confirm_original_open_periods" type="checkbox" value="1" required style="width:auto">
Every original issue and opening accounting month is open; no later or guessed date is substituted.</label>
<label style="grid-column:span 2;display:flex;align-items:center;flex-direction:row;gap:8px">
<input name="confirm_original_cost_not_manual_gl" type="checkbox" value="1" required style="width:auto">
I independently verified there is no previous manual original inventory/equity or source loss GL and that I am a distinct executor.</label>
<button class="btn" type="submit" onclick="return confirm('Post historic source original inventory/equity and separately approved adjustment loss journals atomically? No physical stock will be created.')">Post original opening and adjustment GL</button>
</form>
@endif
</td>
</tr>
@empty<tr><td colspan="6">No signed original-cost financial proposal exists for historical opening adjustment losses.</td></tr>
@endforelse
</tbody></table></div>
</section>
@endsection
