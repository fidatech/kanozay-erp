@extends('layouts.kanozay')
@section('content')
<style>
.bs-panel{border:1px solid #dce5f0;border-radius:14px;background:#fff;padding:18px 20px;margin-bottom:15px}
.bs-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;align-items:end}
.bs-panel label{display:grid;gap:6px;color:#385674;font-size:12px;font-weight:750}
.bs-panel input,.bs-panel select,.bs-panel textarea{border:1px solid #d2dfea;border-radius:9px;padding:9px 11px;min-height:40px;width:100%;max-width:100%}
.bs-table{overflow-x:auto}.bs-table table{width:100%;min-width:940px;border-collapse:collapse}
.bs-table th,.bs-table td{text-align:left;padding:11px 13px;border-bottom:1px solid #e7edf5;vertical-align:top;font-size:12px}
.bs-table th{background:#f4f8fc;color:#445d78}
.bs-stat{background:#f5f9fe;border-radius:11px;padding:14px;color:#1c3e60}
.bs-stat small{display:block;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#62809e}
.bs-stat strong{font-size:19px;display:block;font-variant-numeric:tabular-nums}
.bs-success{color:#137351;font-weight:750}
.bs-muted{color:#718398;font-size:12px}
.bs-warning{background:#fff7e6;border:1px solid #f1d6a2;padding:13px;border-radius:9px;color:#765218}
.bs-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
@media(max-width:880px){.bs-grid{grid-template-columns:1fr}}
</style>
<div class="heading">
<div><h1>{{ $row->number }} · {{ $row->bank_name }}</h1>
<p>{{ $row->period_start }} — {{ $row->period_end }} · {{ $row->currency_code }} · {{ $row->statement_reference }}</p></div>
<div class="actions"><a class="btn btn-light" href="{{ route('finance.bank-reconciliations.index') }}">All bank statements</a><a class="btn btn-light" href="{{ route('finance.journals.index') }}">General ledger journals</a></div>
</div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<section class="bs-panel">
<div class="bs-grid">
<div class="bs-stat"><small>Statement opening</small><strong>{{ number_format((float)$row->statement_opening,4) }} <small>{{ $row->currency_code }}</small></strong></div>
<div class="bs-stat"><small>Statement closing</small><strong>{{ number_format((float)$row->statement_closing,4) }} <small>{{ $row->currency_code }}</small></strong></div>
<div class="bs-stat"><small>Line matching</small><strong>{{ $matchingCount }} / {{ $lines->count() }}</strong><div class="{{ $row->status==='approved'?'bs-success':'bs-muted' }}">{{ ucfirst($row->status) }}</div></div>
</div>
<p class="bs-muted" style="margin-top:12px">Account: {{ $row->bank_institution }} · {{ $row->bank_account_number }}. These balances and transactions belong to the actual bank statement in the displayed original currency. The system never invents FX or an opening journal.</p>
<div class="bs-row" style="margin-top:12px">
<span class="bs-muted">Deposits: {{ (string)$deposit->toScale(4) }} {{ $row->currency_code }}</span>
<span class="bs-muted">Withdrawals: {{ (string)$withdrawal->toScale(4) }} {{ $row->currency_code }}</span>
<span>Calculated statement closing: <strong>{{ (string)$statementExpected->toScale(4) }} {{ $row->currency_code }}</strong></span>
@if(!$statementExpected->isEqualTo((string)$row->statement_closing))
<span class="bs-warning">Source statement movement total does not equal documented closing. Fix the original entries before approval.</span>
@endif
</div>
</section>
@if($row->status==='draft')
@canany(['finance.update','administration.update'])
<section class="bs-panel">
<h2>Add authentic bank transaction</h2>
<form method="POST" action="{{ route('finance.bank-reconciliations.lines.store',$row->id) }}" class="bs-grid">@csrf
<input type="hidden" name="submission_token" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Original bank posting date<input type="date" name="posted_on" min="{{ $row->period_start }}" max="{{ $row->period_end }}" required></label>
<label>Bank direction<select name="direction" required><option value="">Select</option><option value="deposit">Deposit (bank receives)</option><option value="withdrawal">Withdrawal (bank pays)</option></select></label>
<label>Original bank amount · {{ $row->currency_code }}<input name="amount" type="number" min="0.0001" step="0.0001" required></label>
<label>Statement line identifier<input name="bank_reference" minlength="1" maxlength="180" required></label>
<label>Bank description<input name="description" maxlength="500"></label>
<div><button class="btn">Record bank statement transaction</button></div>
</form>
</section>
<section class="bs-panel">
<h2>Bulk import bank-issued CSV</h2>
<p class="bs-muted">Use a UTF-8 CSV with exactly five headers in this order: <code>date,direction,amount,reference,description</code>. Date format YYYY-MM-DD; direction <code>deposit</code> or <code>withdrawal</code>; original currency amounts up to four decimals. Files are limited to 2 MB and 2,000 transactions. The same file imported again is idempotent by statement, source file checksum and row number. No automatic GL posting or matching occurs.</p>
<form method="POST" action="{{ route('finance.bank-reconciliations.import',$row->id) }}" enctype="multipart/form-data" class="bs-grid">@csrf
<label style="grid-column:span 2">CSV from the actual bank<input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label>
<label style="grid-column:1/-1;display:flex;flex-direction:row;gap:9px;align-items:center"><input type="checkbox" name="confirm_source_csv" value="1" required style="width:auto;min-height:auto">I verified the original statement, date range, original currency and signed bank transaction references.</label>
<div><button class="btn">Import bank statement lines</button></div>
</form>
</section>
@endcanany
@endif
<section class="bs-panel">
<h2>Statement lines and original posted GL evidence</h2>
<p class="bs-muted">Matching is strictly 1:1. Each selected ERP payment/receipt must have an exact matching original date, amount, account, currency, debit/credit direction and posted unreversed journal. Timing differences, bank fees and missing transactions require independent source correction; do not match unrelated records just to finish the period.</p>
<div class="bs-table"><table>
<thead><tr><th>Bank source date</th><th>Reference / description</th><th>Deposit / withdrawal</th><th>Source GL match</th><th>Status</th></tr></thead>
<tbody>
@forelse($lines as $line)
<tr>
<td>{{ $line->posted_on }}<div class="bs-muted">Statement #{{ $line->id }}</div></td>
<td><strong>{{ $line->bank_reference }}</strong><div class="bs-muted">{{ $line->description }}</div></td>
<td><strong>{{ number_format((float)$line->amount,4) }} {{ $row->currency_code }}</strong><div>{{ ucfirst($line->direction) }}</div></td>
<td>
@if($line->money_transaction_id)
<div><strong>Transaction #{{ $line->money_transaction_id }}</strong></div>
<div class="bs-muted">{{ $line->money_reference }} · GL {{ $line->money_journal_number }} ({{ $line->money_journal_status }})</div>
@elseif($row->status==='draft')
@canany(['finance.update','administration.update'])
@php($possible=$candidates->get($line->posted_on.'|'.$line->direction.'|'.(string)\Brick\Math\BigDecimal::of((string)$line->amount)->toScale(4),collect()))
@if($possible->isNotEmpty())
<form method="POST" action="{{ route('finance.bank-reconciliations.match',[$row->id,$line->id]) }}" style="display:grid;gap:7px;max-width:330px">@csrf
<select name="money_transaction_id" required>
<option value="">Exact same bank, date and original amount</option>
@foreach($possible as $money)
<option value="{{ $money->id }}">#{{ $money->id }} · {{ $money->reference }} · {{ $money->amount }} {{ $row->currency_code }}</option>
@endforeach
</select>
<button class="btn btn-light" type="submit">Match genuine posted bank GL</button>
</form>
@else
<div class="bs-warning">No eligible posted GL transaction at this exact original bank date, direction and amount. Investigate source ledger or bank timing.</div>
@endif
@endcanany
@endif
</td>
<td>@if($line->money_transaction_id)<span class="bs-success">Matched</span>@else<span class="bs-warning">Unmatched</span>@endif</td>
</tr>
@empty<tr><td colspan="5">No authentic bank statement transactions added. Enter or import the original bank statement lines.</td></tr>
@endforelse
</tbody></table></div>
</section>
@if($row->status==='draft')
@canany(['finance.update','administration.update'])
<section class="bs-panel">
<h2>Independent final bank reconciliation approval</h2>
<p class="bs-warning">This approval is irreversible through this screen. Every bank statement row AND every posted cash/bank transaction for this account and period must match exactly. The calculated closing must equal both the bank's signed closing and the posted original-currency GL balance. The original statement creator cannot approve their own work.</p>
<form method="POST" class="bs-grid" action="{{ route('finance.bank-reconciliations.approve',$row->id) }}">@csrf
<label style="grid-column:1/-1">Signed independent review and proof of original bank statement, unmatched items, fees and source GL
<textarea name="review_notes" minlength="40" maxlength="5000" rows="4" required></textarea></label>
<label style="grid-column:1/-1;display:flex;flex-direction:row;gap:9px;align-items:center"><input style="width:auto;min-height:auto" type="checkbox" name="confirm_all_source_lines" value="1" required>I reviewed every actual source bank transaction and the full original statement-period totals.</label>
<label style="grid-column:1/-1;display:flex;flex-direction:row;gap:9px;align-items:center"><input style="width:auto;min-height:auto" type="checkbox" name="confirm_original_gl" value="1" required>The exact bank-currency GL matches all original bank source events with no stale, duplicate or reversed entries.</label>
<div><button class="btn" type="submit" onclick="return confirm('Sign this bank reconciliation as an independent finance reviewer? It will become immutable.')">Approve source bank reconciliation</button></div>
</form>
</section>
@endcanany
@else
<section class="bs-panel"><p class="bs-success">This original bank statement is independently approved and locked. Its source lines and matched transactions cannot be edited. Finance period closure may reference this signed statement evidence.</p><p class="bs-muted">Approved {{ $row->approved_at }} by user #{{ $row->approved_by }} · {{ $row->review_notes }}</p></section>
@endif
@endsection
