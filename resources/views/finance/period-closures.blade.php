@extends('layouts.kanozay')
@section('content')
<style>
.fp-box{border:1px solid #dce6f0;border-radius:12px;background:white;padding:20px;margin-bottom:15px}
.fp-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.fp-grid label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#4a6683}
.fp-grid input,.fp-grid textarea{padding:10px;width:100%;border:1px solid #d4e1ed;border-radius:7px;box-sizing:border-box}
.fp-scroll{overflow:auto}.fp-box table{min-width:780px;font-size:12px}.fp-box td,.fp-box th{padding:10px}
@media(max-width:850px){.fp-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Finance Period Close & Reopening</h1><p>Monthly accounting locks, balanced GL snapshots and independent reopening authorization.</p></div><a class="btn btn-light" href="{{ route('finance.journals.index') }}">General journal</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<section class="fp-box">
<h2>Accounting month-close readiness</h2>
<p class="muted">This report checks posted source-record consistency for a selected calendar month. It cannot certify external bank statements, inventory counts, statutory tax policy or missing documents that have not been entered into the ERP.</p>
<form method="GET" action="{{ route('finance.periods.index') }}" class="fp-grid" style="margin-bottom:14px">
<label>Review month<input name="month" type="month" max="{{ now()->subMonthNoOverflow()->format('Y-m') }}" value="{{ $readiness['month'] }}" required></label>
<div><button class="btn btn-light" type="submit">Review month</button></div>
</form>
<p><strong>{{ $readiness['month'] }}</strong>
 · @if($readiness['can_close'])No automated source-book blockers detected
 @else Closing is blocked until outstanding source controls are resolved or this calendar month is finished @endif</p>
@if($readiness['blockers'])
<h3>Blocking source records</h3>
<ul>@foreach($readiness['blockers'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
@else
<p class="muted">No known automated source-record blockers were detected for this month. Manual reconciliation is still mandatory.</p>
@endif
@if($readiness['warnings'])
<h3>Manual reconciliation warnings</h3>
<ul>@foreach($readiness['warnings'] as $note)<li>{{ $note }}</li>@endforeach</ul>
@endif
</section>
@canany(['finance.update','administration.update'])
<section class="fp-box"><h2>Close a calendar month</h2>
<p class="muted">Only completed calendar months can be closed. Draft journals, payroll, unresolved bank statement reconciliations, pending disposal accounting and shareholder cashbook omissions block closure. Warnings require manual review of external evidence. Closure prevents further GL posting into the month unless independently reopened.</p>
<form method="POST" class="fp-grid" action="{{ route('finance.periods.close') }}">@csrf
<label>Month (first day)<input name="period_start" type="date" max="{{ now()->subMonthNoOverflow()->startOfMonth()->toDateString() }}" value="{{ $readiness['begin'] }}" required></label>
<label>Approval reference<input name="authorization_reference" minlength="3" maxlength="160" required></label>
<label>Closing notes<textarea name="closing_note" rows="2" minlength="10" maxlength="3000" required></textarea></label>
<label class="fp-grid-full" style="grid-column:1/-1"><input type="checkbox" name="confirm_source_reconciliation" value="1" required style="width:auto">
 I confirm finance reviewed the bank reconciliation evidence, payroll, fixed assets, shareholder capital and distribution records, source books and relevant tax/stock exceptions for this month.</label>
<button class="btn" type="submit" onclick="return confirm('Close this completed accounting month after reviewed external reconciliation?')">Close reviewed month</button></form></section>
@endcanany
<section class="fp-box"><h2>Accounting period history</h2>
<div class="fp-scroll"><table><thead><tr><th>Month</th><th>Status</th><th>Posted journals</th><th>Debit total</th><th>Credit total</th><th>Authorization</th><th>Reopening</th></tr></thead><tbody>
@forelse($periods as $p)
<tr><td>{{ $p->period_start }}</td><td>{{ ucfirst($p->status) }}</td><td>{{ $p->journal_count }}</td>
<td>{{ $p->debit_snapshot }}</td><td>{{ $p->credit_snapshot }}</td><td>{{ $p->authorization_reference }}<br><small>{{ $p->closer_name }}</small></td>
<td>
@if($p->status==='closed')
@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('finance.periods.reopen',$p->id) }}" style="display:grid;gap:6px">@csrf
<input name="reopening_reference" minlength="3" maxlength="160" required placeholder="New approval reference">
<input name="reopening_note" minlength="10" maxlength="3000" required placeholder="Reason for reopening">
<button class="btn btn-light">Request controlled reopen</button></form>
@endcanany
@elseif($p->status==='reopened')
Reopened with independent approval <small>{{ $p->reopening_reference }}</small>
@endif</td></tr>
@empty<tr><td colspan="7">No calendar month has been closed.</td></tr>@endforelse
</tbody></table></div>{{ $periods->links() }}</section>
@endsection