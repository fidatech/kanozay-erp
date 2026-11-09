@extends('layouts.kanozay')
@section('content')
<style>
.br-panel{border:1px solid #dbe5f0;background:#fff;border-radius:14px;padding:20px;margin:0 0 16px;box-shadow:0 4px 22px #1f457308}
.br-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}
.br-panel label{display:grid;gap:7px;font-size:12px;font-weight:750;color:#34516c}
.br-panel input,.br-panel select,.br-panel textarea{width:100%;max-width:100%;min-height:40px;padding:9px;border:1px solid #cddbea;border-radius:8px}
.br-table{overflow-x:auto}.br-table table{width:100%;min-width:810px;border-collapse:collapse}
.br-table th,.br-table td{padding:11px 12px;border-bottom:1px solid #e9eff5;text-align:left;font-size:12px;vertical-align:top}
.br-table th{background:#f5f8fc;color:#4b6480;font-weight:800}
.br-pill{display:inline-block;border-radius:20px;background:#edf4ff;color:#2a5f91;padding:5px 10px;font-weight:700;font-size:11px}
.br-pill.ok{color:#14734d;background:#e9f8ee}
.br-action{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
@media(max-width:850px){.br-grid{grid-template-columns:1fr}}
</style>
<div class="heading">
 <div><h1>Bank statement reconciliation</h1><p>Original bank currency · source GL matching · independent finance approval.</p></div>
 <div class="actions"><a class="btn btn-light" href="{{ route('finance.accounts.index') }}">Bank accounts</a><a class="btn btn-light" href="{{ route('finance.periods.index') }}">Period close</a></div>
</div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<section class="br-panel">
 <h2>Bank statement periods</h2>
 <p class="muted">Create a date-bound statement for one actual bank account and original currency. Statement opening plus deposits less withdrawals must equal the bank's original closing balance and the exact posted source bank GL balance before an independent reviewer can approve it. No artificial FX conversion or guessed opening entry is created.</p>
 <form method="GET" action="{{ route('finance.bank-reconciliations.index') }}" class="br-grid">
 <label>Bank account
 <select name="account_id"><option value="">All accounts</option>
 @foreach($accounts as $bank)
 <option value="{{ $bank->id }}" @selected((string)($filter['account_id']??'')===(string)$bank->id)>{{ $bank->name }} · {{ $bank->currency?->code }}</option>
 @endforeach</select></label>
 <label>Status<select name="status"><option value="">All</option><option value="draft" @selected(($filter['status']??'')==='draft')>Draft / source matching</option><option value="approved" @selected(($filter['status']??'')==='approved')>Approved / locked</option></select></label>
 <div style="align-self:end"><button class="btn btn-light">Filter bank statements</button></div>
 </form>
</section>
@canany(['finance.update','administration.update'])
<section class="br-panel">
 <h2>New bank statement period</h2>
 <form method="POST" action="{{ route('finance.bank-reconciliations.store') }}" class="br-grid">@csrf
 <label>Original bank account<select name="financial_account_id" required><option value="">Select actual bank</option>
 @foreach($accounts as $bank)<option value="{{ $bank->id }}" @selected((string)old('financial_account_id')===(string)$bank->id)>{{ $bank->name }} · {{ $bank->currency?->code }}</option>@endforeach
 </select></label>
 <label>Statement begins<input type="date" name="period_start" max="{{ today()->toDateString() }}" value="{{ old('period_start') }}" required></label>
 <label>Statement ends<input type="date" name="period_end" max="{{ today()->toDateString() }}" value="{{ old('period_end') }}" required></label>
 <label>Signed statement opening balance (source currency)<input name="statement_opening" type="number" step="0.0001" value="{{ old('statement_opening') }}" required></label>
 <label>Signed statement closing balance (source currency)<input name="statement_closing" type="number" step="0.0001" value="{{ old('statement_closing') }}" required></label>
 <label>Bank-issued statement number/reference<input name="statement_reference" minlength="4" maxlength="180" value="{{ old('statement_reference') }}" required></label>
 <div class="br-action" style="grid-column:1/-1"><button class="btn" type="submit">Create source statement reconciliation</button></div>
 </form>
</section>
@endcanany
<section class="br-panel">
 <h2>Source statement register</h2>
 <div class="br-table"><table>
 <thead><tr><th>Period / reference</th><th>Bank / source currency</th><th>Statement opening</th><th>Statement closing</th><th>Signed review</th><th>Action</th></tr></thead>
 <tbody>
 @forelse($periods as $period)
 <tr>
 <td><strong>{{ $period->number }}</strong><div>{{ $period->period_start }} — {{ $period->period_end }}</div><small>{{ $period->statement_reference }}</small></td>
 <td><strong>{{ $period->bank_name }}</strong><div>{{ $period->currency_code }}</div></td>
 <td>{{ number_format((float)$period->statement_opening,4) }}</td>
 <td>{{ number_format((float)$period->statement_closing,4) }}</td>
 <td><span class="br-pill {{ $period->status==='approved'?'ok':'' }}">{{ strtoupper($period->status) }}</span>
 @if($period->approved_by)<div>Reviewer: {{ $period->reviewer_name }} ({{ $period->approved_at }})</div>@endif
 </td>
 <td><a class="btn btn-light" href="{{ route('finance.bank-reconciliations.show',$period->id) }}">Open bank statement</a></td>
 </tr>
 @empty<tr><td colspan="6" class="muted">No signed bank statement periods have been entered. Create one from a real bank-issued statement.</td></tr>
 @endforelse
 </tbody></table></div>
 {{ $periods->links() }}
</section>
@endsection
