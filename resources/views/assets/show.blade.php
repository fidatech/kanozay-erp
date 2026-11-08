@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>{{ $asset->asset_tag }} · {{ $asset->name }}</h1><p>{{ $asset->category->name }} · {{ ucfirst($asset->status) }}</p></div><a class="btn btn-light" href="{{ route('workspace.page',['assets','register']) }}">Asset register</a></div>
@if(session('success'))<p role="status" class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="notice notice-warning">{{ $errors->first() }}</p>@endif
<div class="card" style="padding:20px;margin-bottom:16px">
<h2>Acquisition and GL capitalization</h2>
<p>Date: {{ $asset->acquired_on->toDateString() }} · Cost: {{ $asset->currency->code }} {{ $asset->acquisition_cost }}</p>
<p>Source: {{ $asset->source_reference }}</p>
@if($asset->notes)<p>{{ $asset->notes }}</p>@endif
<p>Current custodian: {{ $asset->custodian?->full_name ?? 'Unassigned' }}</p>
@if($capitalization)
<p style="padding:12px;background:#f3faf5;border:1px solid #cde6d4;border-radius:8px;color:#166b47">
<strong>Posted acquisition reconciled</strong> · {{ $capitalization->journal_number }}
 · PPE {{ $capitalization->asset_ledger_code }} · Reviewed {{ $capitalization->created_at }}
</p>
<p class="muted">Reference: {{ $capitalization->reconciliation_reference }}. This reconciliation did not create a duplicate purchase or journal.</p>
@else
<p style="padding:12px;background:#fff8eb;border:1px solid #eed5a5;border-radius:8px;color:#795219">
<strong>Acquisition not reconciled to GL.</strong>
New depreciation is blocked until finance matches an exact posted PPE debit with the original source reference, amount and currency.
The asset registration itself has not posted an acquisition journal.
</p>
@canany(['finance.update','administration.update'])
@if($capitalizationCandidates->isNotEmpty())
<form method="POST" action="{{ route('assets.capitalization.reconcile',$asset) }}" style="display:grid;gap:11px;max-width:680px">
@csrf
<label>Matching posted acquisition debit
<select name="journal_line_id" required><option value="">Select verified PPE debit</option>
@foreach($capitalizationCandidates as $candidate)
<option value="{{ $candidate->journal_line_id }}">{{ $candidate->journal_number }} · {{ $candidate->entry_date }} · {{ $candidate->ledger_code }} · {{ $asset->currency?->code }} {{ $candidate->debit }}</option>
@endforeach
</select></label>
<label>Reconciliation reference<input name="reconciliation_reference" required minlength="4" maxlength="180" placeholder="Reviewed purchase file / approved opening balance"></label>
<label>Evidence notes<textarea name="evidence_notes" required minlength="15" maxlength="3000" rows="2" placeholder="Explain how the journal and original asset registration were matched"></textarea></label>
<div><button class="btn" type="submit" onclick="return confirm('Confirm this original acquisition debit belongs exclusively to this fixed asset?')">Reconcile acquisition</button></div>
</form>
@else
<p class="muted">No matching posted, unreversed acquisition debit is currently available. Finance must post or reconcile the original source transaction; this screen will not invent an acquisition entry.</p>
@endif
@endcanany
@endif
</div>
@if(auth()->user()->canAny(['maintenance.update','finance.update','administration.update']) && $asset->status === 'active' && ! $asset->custodian_id)<div class="card" style="padding:20px;margin-bottom:16px"><h2>Assign asset</h2><form method="POST" action="{{ route('assets.assignments.store',$asset) }}" class="form-grid">@csrf<label>Employee<select name="employee_profile_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_number }} — {{ $employee->full_name }}</option>@endforeach</select></label><label>Assigned on<input type="date" name="assigned_on" value="{{ now()->toDateString() }}" required></label><label>Notes<textarea name="notes" maxlength="2000"></textarea></label><div><button class="btn" type="submit">Record assignment</button></div></form></div>@endif
<div class="card" style="padding:20px"><h2>Assignment history</h2><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Assigned</th><th>Returned</th><th>Notes</th><th>Action</th></tr></thead><tbody>@forelse($assignments as $assignment)<tr><td>{{ $assignment->employee->employee_number }} — {{ $assignment->employee->full_name }}</td><td>{{ $assignment->assigned_on->toDateString() }}</td><td>{{ $assignment->returned_on?->toDateString() ?? 'Current' }}</td><td>{{ $assignment->notes }}</td><td>@if(! $assignment->returned_on && auth()->user()->canAny(['maintenance.update','finance.update','administration.update']))<form method="POST" action="{{ route('assets.assignments.return',[$asset,$assignment]) }}">@csrf<input type="date" name="returned_on" value="{{ now()->toDateString() }}" required><button class="btn btn-secondary" type="submit">Record return</button></form>@endif</td></tr>@empty<tr><td colspan="5" class="empty">No assignment recorded.</td></tr>@endforelse</tbody></table></div></div>
@endsection
