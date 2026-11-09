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
@endsection
