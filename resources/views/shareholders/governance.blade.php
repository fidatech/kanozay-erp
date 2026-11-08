@extends('layouts.kanozay')
@section('content')
<style>
.sh-panel{padding:20px;border:1px solid #dfe8f2;background:#fff;border-radius:12px;margin-bottom:15px}
.sh-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.sh-form label{display:grid;gap:6px;font-size:12px;color:#496680}
.sh-form input,.sh-form select,.sh-form textarea{min-width:0;width:100%;box-sizing:border-box;padding:10px;border:1px solid #d6e2ec;border-radius:8px}
.sh-wrap{overflow:auto}.sh-panel table{min-width:760px;font-size:12px}.sh-panel td,.sh-panel th{padding:11px}
.sh-note{border-radius:8px;padding:11px;background:#fff7e9;color:#77572b;font-size:12px;line-height:1.6}
@media(max-width:850px){.sh-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $pageTitle }} · Shareholder Governance</h1><p>Capital ownership, board resolutions and approved distributions</p></div><a class="btn btn-light" href="{{ route('workspace.page',['shareholders','register']) }}">Shareholders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@if($page==='ownership')
<section class="sh-panel"><h2>Documented ownership by share class</h2>
<p class="sh-note">Ownership is entered from approved board records; capital contributed does not imply a particular ownership percentage. Combined percentages in each class cannot exceed 100%. Zero removes current entitlement without deleting past ownership history. Class amendments are chronological and every amendment is recorded.</p>
@canany(['shareholders.update','administration.update'])
<form method="POST" action="{{ route('shareholders.governance.ownership') }}" class="sh-form">@csrf
<label>Shareholder<select name="shareholder_id" required><option value="">Choose shareholder</option>@foreach($shareholders as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<label>Share class<input name="share_class" value="Ordinary" required maxlength="60"></label>
<label>Ownership %<input name="percentage" inputmode="decimal" required></label>
<label>Effective date<input type="date" name="effective_on" value="{{ now()->toDateString() }}" required></label>
<label>Board approval reference<input name="approval_reference" required minlength="4" maxlength="180"></label>
<button class="btn" type="submit">Record approved ownership</button>
</form>@endcanany
<form method="GET" action="{{ route('workspace.page',['shareholders','ownership']) }}" style="display:flex;align-items:end;gap:8px;flex-wrap:wrap;margin:14px 0">
<label>Historical ownership as of<input name="as_of" type="date" max="{{ now()->toDateString() }}" value="{{ $ownershipAsOf }}" required></label>
<button class="btn btn-light" type="submit">View ownership on date</button>
</form>
<h3>Board-approved cap table on {{ $ownershipAsOf }}</h3>
<div class="sh-wrap"><table><thead><tr><th>Share class</th><th>Shareholder</th><th>Ownership %</th><th>Effective date</th><th>Signed evidence</th></tr></thead><tbody>
@forelse($ownershipSnapshot as $historic)
<tr><td>{{ $historic->share_class }}</td><td>{{ $historic->shareholder_name }}</td>
<td>{{ $historic->new_percentage }}%</td><td>{{ $historic->effective_on }}</td>
<td>{{ $historic->approval_reference }}</td></tr>
@empty<tr><td colspan="5">No documented ownership interests were effective on this date.</td></tr>@endforelse
</tbody></table></div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
@foreach($ownershipClassTotals as $shareClass=>$percent)
<span class="sh-note">{{ $shareClass }} documented: {{ $percent }}% @if(\Brick\Math\BigDecimal::of((string)$percent)->isLessThan('100')) · Not fully allocated @endif</span>
@endforeach
</div>
<p class="muted">An incomplete share class is not assumed to have unrecorded shareholders. This register reflects signed historical ownership changes. Payout entitlements require independent resolution-specific amounts.</p>
<h3>Current ownership register</h3>
<div class="sh-wrap"><table><thead><tr><th>Shareholder</th><th>Share class</th><th>Ownership %</th><th>Effective date</th><th>Approval</th></tr></thead><tbody>
@forelse($ownership as $o)<tr><td>{{ $o->shareholder_name }}</td><td>{{ $o->share_class }}</td><td>{{ $o->percentage }}%</td><td>{{ $o->effective_on }}</td><td>{{ $o->approval_reference }}</td></tr>
@empty<tr><td colspan="5">Ownership percentages are not established until board approvals are entered.</td></tr>@endforelse
</tbody></table></div></section>
@elseif($page==='resolutions')
@canany(['shareholders.update','administration.update'])
<section class="sh-panel"><h2>Register signed board resolution</h2>
<form class="sh-form" method="POST" action="{{ route('shareholders.governance.resolution') }}">@csrf
<label>Number<input name="number" required maxlength="45"></label>
<label>Title<input name="title" required maxlength="180"></label>
<label>Purpose<select name="purpose"><option value="governance">Governance decision</option><option value="distribution">Profit distribution</option></select></label>
<label>Currency (required for distribution)<select name="currency_id"><option value="">No currency</option>@foreach($currencies as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach</select></label>
<label>Distribution cap<input name="distribution_cap" inputmode="decimal"></label>
<label>Resolution date<input name="resolution_on" type="date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Signed document reference<input name="document_reference" required minlength="4" maxlength="180"></label>
<label>Notes<input name="notes" maxlength="5000"></label>
<button class="btn" type="submit">Register draft resolution</button></form>
</section>@endcanany
<section class="sh-panel">
<h2>Document each recipient's distribution entitlement</h2>
<p class="sh-note">Before independently approving a distribution resolution, assign an explicit board-referenced amount to each authorized recipient. Entitlements are in the resolution's original currency, are immutable after approval and cannot exceed the total authorized ceiling. Ownership percentages and contributions do not automatically set profit entitlements.</p>
@canany(['shareholders.update','administration.update'])
<form method="POST" action="{{ route('shareholders.governance.allocations') }}" class="sh-form">
@csrf
<label>Draft distribution resolution<select name="shareholder_resolution_id" required>
<option value="">Choose signed draft resolution</option>
@foreach($resolutions as $resolution)
@if($resolution->purpose==='distribution' && $resolution->status==='draft')
<option value="{{ $resolution->id }}">{{ $resolution->number }} · Total ceiling {{ $resolution->distribution_cap }} · Currency #{{ $resolution->currency_id }}</option>
@endif
@endforeach
</select></label>
<label>Authorized shareholder<select name="shareholder_id" required>
<option value="">Choose recipient</option>
@foreach($shareholders as $party)<option value="{{ $party->id }}">{{ $party->name }}</option>@endforeach
</select></label>
<label>Recipient's approved amount<input name="authorized_amount" inputmode="decimal" required placeholder="0.0000"></label>
<label>Signed allocation reference<input name="approval_reference" required minlength="4" maxlength="180" placeholder="Board resolution / allocation schedule"></label>
<button class="btn" type="submit">Save recipient entitlement</button>
</form>
@endcanany
<div class="sh-wrap"><table><thead><tr><th>Board resolution</th><th>Recipient</th><th>Currency</th><th>Authorized ceiling</th><th>Status</th><th>Reference</th></tr></thead><tbody>
@forelse($allocations as $allocation)
<tr><td>{{ $allocation->resolution_number }}</td><td>{{ $allocation->shareholder_name }}</td>
<td>{{ $allocation->currency_code }}</td><td>{{ $allocation->authorized_amount }}</td>
<td>{{ ucfirst($allocation->resolution_status) }}</td><td>{{ $allocation->approval_reference }}</td></tr>
@empty<tr><td colspan="6">No recipient distribution entitlements have been recorded.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="sh-panel"><h2>Board resolution register</h2><div class="sh-wrap"><table><thead><tr><th>Resolution</th><th>Purpose</th><th>Date</th><th>Distribution ceiling</th><th>Status</th><th>Independent review</th></tr></thead><tbody>
@forelse($resolutions as $r)<tr><td>{{ $r->number }}<br>{{ $r->title }}</td><td>{{ ucfirst($r->purpose) }}</td><td>{{ $r->resolution_on }}</td><td>{{ $r->distribution_cap?:'—' }}</td><td>{{ ucfirst($r->status) }}</td><td>
@if($r->status==='draft')
@if($r->purpose==='distribution')
<div class="muted" style="margin-bottom:5px">{{ $allocations->where('shareholder_resolution_id',$r->id)->count() }} recipient entitlement(s) recorded</div>
@endif
@canany(['shareholders.update','administration.update'])
<form method="POST" action="{{ route('shareholders.governance.decide',$r->id) }}" style="display:flex;gap:5px">@csrf
<button name="decision" value="approved" class="btn btn-secondary">Approve</button>
<button name="decision" value="rejected" class="btn btn-light">Reject</button>
</form>@endcanany
@else Reviewed @endif
</td></tr>@empty<tr><td colspan="6">No board resolutions recorded.</td></tr>@endforelse
</tbody></table></div></section>
@else
<section class="sh-panel"><h2>Approved profit distribution</h2>
<p class="sh-note">A distribution requires an independently approved board resolution, a specific signed entitlement for this recipient, and actual posted retained earnings (ledger 3200) in the payout currency as of the payment date. Neither total payments nor individual recipient payments may exceed their approved ceilings. Owner drawings are separate from profit distributions. No currency conversion or assumed ownership-based entitlement is applied.</p>
@canany(['shareholders.update','administration.update'])
@if(auth()->user()->canAny(['finance.update','administration.update']))
<form method="POST" class="sh-form" action="{{ route('shareholders.governance.distribute') }}">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Approved distribution resolution<select name="shareholder_resolution_id" required><option value="">Select resolution</option>
@foreach($resolutions as $r)@if($r->status==='approved' && $r->purpose==='distribution')<option value="{{ $r->id }}">{{ $r->number }} · {{ $r->distribution_cap }}</option>@endif @endforeach</select></label>
<label>Payee shareholder<select name="shareholder_id" required><option value="">Choose shareholder</option>@foreach($shareholders as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<label>Payout account<select name="financial_account_id" required><option value="">Choose account</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
<label>Paid on<input type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label>Approved amount<input name="amount" inputmode="decimal" required></label>
<button class="btn" type="submit" onclick="return confirm('Post approved distribution against retained earnings and cash ledger?')">Pay approved distribution</button>
</form>@endif
@endcanany</section>
<section class="sh-panel"><h2>Approved payee entitlements</h2>
<div class="sh-wrap"><table><thead><tr><th>Resolution</th><th>Shareholder</th><th>Authorized amount</th><th>Currency</th><th>Approval evidence</th></tr></thead><tbody>
@forelse($allocations->where('resolution_status','approved') as $allocation)
<tr><td>{{ $allocation->resolution_number }}</td><td>{{ $allocation->shareholder_name }}</td>
<td>{{ $allocation->authorized_amount }}</td><td>{{ $allocation->currency_code }}</td>
<td>{{ $allocation->approval_reference }}</td></tr>
@empty<tr><td colspan="5">No approved individual distribution entitlements. Approve the allocation schedule and resolution before any payouts.</td></tr>@endforelse
</tbody></table></div></section>
<section class="sh-panel"><h2>Posted profit distributions</h2><div class="sh-wrap"><table><thead><tr><th>Shareholder</th><th>Resolution</th><th>Payment date</th><th>Amount</th><th>GL journal</th></tr></thead><tbody>
@forelse($distributions as $d)<tr><td>{{ $d->shareholder_name }}</td><td>{{ $d->resolution_number }}</td><td>{{ $d->paid_on }}</td><td>{{ $d->amount }} {{ $d->currency_code }}</td><td>Journal #{{ $d->journal_entry_id }}</td></tr>
@empty<tr><td colspan="5">No approved profit distributions posted.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
