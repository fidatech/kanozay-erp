@extends('layouts.kanozay')
@section('content')
<style>
.hfc-wrap{display:grid;gap:16px}
.hfc-sheet{background:#fff;border:1px solid #dce6f0;border-radius:12px;padding:18px}
.hfc-heading{display:flex;gap:15px;flex-wrap:wrap;justify-content:space-between;align-items:start}
.hfc-note{padding:14px;border:1px solid #e9ce9a;border-radius:9px;background:#fff8e9;line-height:1.7}
.hfc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.hfc-grid label{display:grid;gap:6px}
.hfc-grid input,.hfc-grid select,.hfc-grid textarea{width:100%;border:1px solid #d7e1ed;border-radius:8px;padding:10px;min-height:40px}
.hfc-scroll{overflow-x:auto}.hfc-scroll table{min-width:870px}
.hfc-scroll th,.hfc-scroll td{padding:11px;text-align:start;vertical-align:top}
.hfc-status{font-weight:700}.hfc-id{font-family:ui-monospace,monospace;font-size:12px}
.hfc-links{display:flex;gap:8px;flex-wrap:wrap}
</style>
<div class="hfc-wrap">
<div class="hfc-heading">
<div><h1>Historical Financial Correction Center</h1>
<p class="muted">Immutable source documents, independently reviewed accounting exceptions and controlled original-currency repair.</p></div>
<div class="hfc-links">
<a class="btn btn-light" href="{{ route('reports.manufacturing-financial.index') }}">Manufacturing &amp; COGS audit</a>
<a class="btn btn-light" href="{{ route('finance.periods.index') }}">Accounting period close</a>
</div>
</div>
<div class="hfc-note"><strong>Only previously capitalized, fully evidenced invoice COGS can be automatically repaired.</strong>
Other historical exceptions remain open investigation records until a documented, independently approved source-accounting adjustment exists. This screen cannot invent opening stock values, backdate a closed period, create missing supplier payables or change posted inventory documents. All monetary postings retain the source currency.</div>
<section class="hfc-sheet">
<h2>Recorded historical correction cases</h2>
<form method="GET" action="{{ route('finance.historical-corrections.index') }}" class="hfc-grid" style="margin-bottom:13px">
<label>Case status<select name="status"><option value="">All statuses</option>
@foreach(['submitted'=>'Submitted','approved'=>'Approved for correction','rejected'=>'Rejected','resolved'=>'Resolved','withdrawn'=>'Withdrawn'] as $status=>$label)
<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ $label }}</option>
@endforeach</select></label>
<label>Exception type<select name="type"><option value="">All types</option>
@foreach($types as $code=>$name)<option value="{{ $code }}" @selected(($filters['type']??'')===$code)>{{ $name }}</option>@endforeach
</select></label>
<label>Source ID<input type="number" name="source_id" min="1" value="{{ $filters['source_id']??'' }}"></label>
<div style="display:flex;gap:8px;align-items:end"><button class="btn" type="submit">Filter</button>
<a class="btn btn-light" href="{{ route('finance.historical-corrections.index') }}">Reset</a></div>
</form>
<div class="hfc-scroll"><table>
<thead><tr><th>Case</th><th>Original source</th><th>Source date</th><th>Review status</th><th>Requester / reviewer</th><th>Details</th></tr></thead>
<tbody>
@forelse($cases as $row)
<tr><td><strong class="hfc-id">{{ $row->case_number }}</strong></td>
<td><strong>{{ $types[$row->case_type]??$row->case_type }}</strong><div>{{ $row->source_reference }} · #{{ $row->source_id }}</div></td>
<td>{{ $row->source_date }}</td>
<td><span class="hfc-status">{{ ucfirst($row->status) }}</span></td>
<td>Requester #{{ $row->requested_by }} @if($row->reviewed_by) · Reviewer #{{ $row->reviewed_by }} @endif</td>
<td><a href="{{ route('finance.historical-corrections.show',$row->id) }}">Review evidence</a></td></tr>
@empty<tr><td colspan="6">No historical correction cases match these filters.</td></tr>@endforelse
</tbody></table></div>
{{ $cases->links() }}
</section>
@if(auth()->user()->canAny(['finance.update','administration.update']))
<section class="hfc-sheet">
<h2>Submit source discrepancy for independent review</h2>
<p class="muted">Identify the existing source record. The original document fingerprint is frozen automatically, and the same source can have only one unresolved case.</p>
<form method="POST" action="{{ route('finance.historical-corrections.store') }}">
@csrf
<div class="hfc-grid">
<label>Exception type
<select name="case_type" required><option value="">Choose original source category</option>
@foreach($types as $code=>$name)<option value="{{ $code }}" @selected(old('case_type',request('type'))===$code)>{{ $name }}</option>@endforeach
</select></label>
<label>Original source record ID
<input type="number" name="source_id" min="1" required value="{{ old('source_id',request('source_id')) }}"></label>
<label style="grid-column:1/-1">Discrepancy identified
<textarea name="identified_discrepancy" rows="3" minlength="20" maxlength="4000" required placeholder="State which physical or accounting source document does not reconcile.">{{ old('identified_discrepancy') }}</textarea></label>
<label style="grid-column:1/-1">Signed source evidence and reconciliation notes
<textarea name="source_evidence" rows="3" minlength="20" maxlength="5000" required placeholder="Describe original invoice/GRN, journal, inventory movement, dates and independent supporting documents.">{{ old('source_evidence') }}</textarea></label>
</div>
<label style="display:flex;align-items:center;gap:8px;margin:12px 0">
<input type="checkbox" name="confirm_original_source" value="1" required>
I verified the exact source ID and will not replace missing historical values with assumptions.
</label>
<button class="btn" type="submit" onclick="return confirm('Submit this immutable-source exception to a different finance reviewer? No GL or physical stock will change.')">Submit correction case</button>
</form>
</section>
@endif
<section class="hfc-sheet">
<h2>Recent source exceptions to investigate</h2>
<p class="muted">These are limited discovery samples (up to 20 source IDs per category), not a complete audit or automatic posting instruction. Inspect the original records before filing a case.</p>
<div class="hfc-grid">
@foreach($candidates as $type=>$ids)
<div style="padding:12px;border:1px solid #e2eaf2;border-radius:9px">
<strong>{{ $types[$type] }}</strong>
<div class="hfc-links" style="margin-top:9px">
@forelse($ids as $id)
<a href="{{ route('finance.historical-corrections.index',['type'=>$type,'source_id'=>$id]) }}" class="btn btn-light" style="font-size:12px">Source #{{ $id }}</a>
@empty<span class="muted">No recent exceptions sampled</span>@endforelse
</div>
</div>
@endforeach
</div>
</section>
</div>
@endsection
