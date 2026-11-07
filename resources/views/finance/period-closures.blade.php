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
@canany(['finance.update','administration.update'])
<section class="fp-box"><h2>Close a calendar month</h2>
<p class="muted">The entire month is locked for new GL posting after close. Draft journals and unbalanced journal totals block closure. This does not independently certify reconciliation of source books, taxes, inventory or bank statements.</p>
<form method="POST" class="fp-grid" action="{{ route('finance.periods.close') }}">@csrf
<label>Month (first day)<input name="period_start" type="date" value="{{ now()->startOfMonth()->toDateString() }}" required></label>
<label>Approval reference<input name="authorization_reference" minlength="3" maxlength="160" required></label>
<label>Closing notes<textarea name="closing_note" rows="2" minlength="10" maxlength="3000" required></textarea></label>
<button class="btn">Close reviewed month</button></form></section>
@endcanany
<section class="fp-box"><h2>Accounting period history</h2>
<div class="fp-scroll"><table><thead><tr><th>Month</th><th>Status</th><th>Posted journals</th><th>Debit total</th><th>Credit total</th><th>Authorization</th><th>Reopening</th></tr></thead><tbody>
@forelse($periods as $p)
<tr><td>{{ $p->period_start }}</td><td>{{ ucfirst($p->status) }}</td><td>{{ $p->journal_count }}</td>
<td>{{ $p->debit_snapshot }}</td><td>{{ $p->credit_snapshot }}</td><td>{{ $p->authorization_reference }}<br><small>{{ $p->closer_name }}</small></td>
<td>@if($p->status==='closed')@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('finance.periods.reopen',$p->id) }}" style="display:grid;gap:6px">@csrf
<input name="reopening_reference" minlength="3" maxlength="160" required placeholder="New approval reference">
<input name="reopening_note" minlength="10" maxlength="3000" required placeholder="Reason for reopening">
<button class="btn btn-light">Request controlled reopen</button></form>@endcanany
@elseif($p->status==='reopened')Reopened with independent approval@endif</td></tr>
@empty<tr><td colspan="7">No calendar month has been closed.</td></tr>@endforelse
</tbody></table></div>{{ $periods->links() }}</section>
@endsection