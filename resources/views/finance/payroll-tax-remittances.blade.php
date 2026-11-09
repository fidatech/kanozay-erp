@extends('layouts.kanozay')
@section('content')
<style>
.ptr-box{border:1px solid #dbe6f1;border-radius:13px;padding:18px 20px;margin:0 0 15px;background:#fff}
.ptr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.ptr-box label{display:grid;gap:6px;color:#3d5673;font-size:12px;font-weight:750}
.ptr-box input:not([type=checkbox]),.ptr-box select,.ptr-box textarea{width:100%;max-width:100%;min-height:39px;padding:9px;border:1px solid #d0dfed;border-radius:8px}
.ptr-scroll{overflow-x:auto}.ptr-scroll table{border-collapse:collapse;width:100%;min-width:970px}
.ptr-scroll td,.ptr-scroll th{border-bottom:1px solid #e8eef5;padding:12px;text-align:left;vertical-align:top;font-size:12px}
.ptr-scroll th{background:#f5f9fc;color:#4a6480}
.ptr-chip{display:inline-block;border-radius:20px;padding:4px 10px;font-weight:750;color:#395c8b;background:#eaf2ff}
.ptr-chip.posted{background:#e7f6ed;color:#14734e}
.ptr-warning{padding:12px;border:1px solid #eed9ac;border-radius:8px;background:#fff8e9;color:#755316}
.ptr-flex{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ptr-inline{display:grid;gap:7px;min-width:210px}
@media(max-width:890px){.ptr-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Employee withholding tax remittances</h1>
<p>Pay only genuine employee withholding already accrued by an approved payroll period, in the same original currency, through an independently approved bank/cash GL.</p></div>
<div class="actions"><a class="btn btn-light" href="{{ route('hr.payroll.tax-policies.index') }}">Approved payroll tax policies</a><a class="btn btn-light" href="{{ route('finance.bank-reconciliations.index') }}">Bank statement reconciliation</a></div></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<section class="ptr-box">
<h2>Source-accounting rules</h2>
<p class="ptr-warning">This screen uses the company's independently approved payroll tax policy and actual posted withholding credits. It does not determine statutory tax rates, withholding eligibility, due dates, or government beneficiary details. Those must be verified from current applicable law and original payment instructions. No tax expense is booked twice. The remittance debits the existing withholding payable and credits the actual cash/bank asset only after separate finance proposal, review and execution.</p>
</section>
@canany(['finance.update','administration.update'])
<section class="ptr-box">
<h2>Submit new source-supported tax payment</h2>
<form method="POST" action="{{ route('finance.payroll-tax-remittances.store') }}" class="ptr-grid">@csrf
<input type="hidden" name="submission_token" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Actual approved salary month and currency
<select name="payroll_period_id" required>
<option value="">Choose posted payroll period</option>
@foreach($periods as $period)
<option value="{{ $period->id }}" @selected((string)old('payroll_period_id')===(string)$period->id)>{{ $period->period_start->format('Y-m') }} · {{ $period->currency?->code }} · #{{ $period->id }} ({{ $period->status }})</option>
@endforeach
</select></label>
<label>Already independently approved withholding policy
<select name="payroll_tax_policy_id" required>
<option value="">Choose original tax policy</option>
@foreach($policies as $policy)
<option value="{{ $policy->id }}" @selected((string)old('payroll_tax_policy_id')===(string)$policy->id)>{{ $policy->code }} · {{ $policy->currency?->code }} · effective {{ $policy->effective_from->format('Y-m-d') }}</option>
@endforeach
</select></label>
<label>Actual bank or cash payment source
<select name="financial_account_id" required>
<option value="">Choose real payment account</option>
@foreach($accounts as $account)
<option value="{{ $account->id }}" @selected((string)old('financial_account_id')===(string)$account->id)>{{ $account->name }} · {{ $account->currency?->code }} · {{ $account->type }}</option>
@endforeach
</select></label>
<label>Actual liability amount to remit (up to 4 decimals)
<input type="number" name="amount" min="0.0001" step="0.0001" value="{{ old('amount') }}" required></label>
<label>Original payment date
<input type="date" name="remit_on" value="{{ old('remit_on',today()->toDateString()) }}" max="{{ today()->toDateString() }}" required></label>
<label>Official payment demand / signed source reference
<input name="evidence_reference" minlength="4" maxlength="180" value="{{ old('evidence_reference') }}" required></label>
<label style="grid-column:1/-1">Document the authentic employee withholding, government instruction, payee evidence and expected bank transaction
<textarea name="source_notes" rows="4" minlength="60" maxlength="5000" required>{{ old('source_notes') }}</textarea></label>
<label style="grid-column:1/-1;display:flex;align-items:center;flex-direction:row;gap:9px">
<input type="checkbox" style="width:auto" value="1" name="confirm_original_withholding" required>
I verified the original approved employee salary deductions, tax policy, same-currency liability and official payment instruction. Submitting this form DOES NOT transfer funds or book a journal.</label>
<div><button type="submit" class="btn">Submit independently reviewed remittance</button></div>
</form>
</section>
@endcanany
<section class="ptr-box">
<h2>Source remittance register</h2>
<form method="GET" action="{{ route('finance.payroll-tax-remittances.index') }}" class="ptr-flex" style="margin:12px 0">
<select name="status" style="max-width:180px"><option value="">All states</option>
@foreach(['submitted','approved','rejected','posted'] as $status)
<option value="{{ $status }}" @selected(($filter['status']??'')===$status)>{{ ucfirst($status) }}</option>
@endforeach</select>
<select name="period_id" style="max-width:240px"><option value="">All payroll months</option>
@foreach($periods as $period)
<option value="{{ $period->id }}" @selected((string)($filter['period_id']??'')===(string)$period->id)>{{ $period->period_start->format('Y-m') }} · {{ $period->currency?->code }}</option>
@endforeach</select>
<button class="btn btn-light">Filter</button>
</form>
<div class="ptr-scroll"><table>
<thead><tr><th>Payroll / original tax policy</th><th>Amount / payment</th><th>Evidence and source GL</th><th>Independent authorization / execution</th></tr></thead>
<tbody>
@forelse($records as $record)
<tr>
<td><strong>Request #{{ $record->id }}</strong>
<div>{{ substr((string)$record->period_start,0,7) }} · {{ $record->policy_code }}</div>
<div class="ptr-chip {{ $record->status==='posted'?'posted':'' }}">{{ strtoupper($record->status) }}</div>
<div class="muted">{{ $record->policy_name }}</div></td>
<td><strong>{{ number_format((float)$record->amount,4) }} {{ $record->currency_code }}</strong>
<div>From {{ $record->account_name }}</div>
<div>{{ $record->remit_on }}</div>
<div class="muted">Original approved withheld total {{ $record->source_total_withheld }} {{ $record->currency_code }}</div>
</td>
<td><strong>{{ $record->evidence_reference }}</strong>
<div class="muted" style="white-space:pre-wrap">{{ $record->source_notes }}</div>
@if($record->journal_entry_id)
<div>Posted journal: <strong>{{ $record->journal_number }}</strong> ({{ $record->journal_status }})</div>
<div>Bank/payment transaction #{{ $record->money_transaction_id }}</div>
@else<div class="muted">No bank transfer or tax GL payment posted.</div>@endif
</td>
<td>
<div>Proposed by #{{ $record->requested_by }}</div>
@if($record->reviewed_by)<div>Finance reviewed by #{{ $record->reviewed_by }}</div><div class="muted">{{ $record->review_reference }} · {{ $record->review_notes }}</div>@endif
@if($record->executed_by)<div>Final payer #{{ $record->executed_by }} ({{ $record->executed_at }})</div>@endif
@if($record->status==='submitted')
@canany(['finance.update','administration.update'])
@if((int)$record->requested_by!==auth()->id())
<form method="POST" action="{{ route('finance.payroll-tax-remittances.review',$record->id) }}" class="ptr-inline" style="margin-top:10px">@csrf
<label>Independent finance decision<select name="decision" required>
<option value="">Choose</option><option value="approve">Approve source liability payment</option><option value="reject">Reject unverified payment</option></select></label>
<label>Reviewer reference<input name="review_reference" minlength="4" maxlength="180" required></label>
<label>Evidence and ledger audit<textarea name="review_notes" minlength="80" maxlength="5000" rows="4" required></textarea></label>
<label style="display:flex;flex-direction:row;align-items:center"><input style="width:auto" type="checkbox" name="confirm_independent_tax_review" value="1" required>Source, policy, actual withholding, currency, bank payment reviewed independently.</label>
<button type="submit" class="btn btn-light">Sign review</button>
</form>
@endif
@endcanany
@elseif($record->status==='approved')
@canany(['finance.update','administration.update'])
@if((int)$record->requested_by!==auth()->id() && (int)$record->reviewed_by!==auth()->id())
<form method="POST" action="{{ route('finance.payroll-tax-remittances.post',$record->id) }}" class="ptr-inline" style="margin-top:10px">@csrf
<p class="ptr-warning">Final execution posts a bank/cash credit and actual withholding liability debit in one transaction. Original payroll accrual and salaries remain unchanged.</p>
<label style="display:flex;flex-direction:row;align-items:center"><input type="checkbox" name="confirm_source_liability" value="1" style="width:auto" required>Actual posted tax payable, original currency and open payment date checked.</label>
<label style="display:flex;flex-direction:row;align-items:center"><input type="checkbox" name="confirm_original_payment" value="1" style="width:auto" required>Final bank/cash transaction and official payment instruction verified.</label>
<button type="submit" class="btn" onclick="return confirm('Post exactly the independently approved tax liability/bank payment now?')">Pay and post tax liability</button>
</form>
@endif
@endcanany
@endif
</td>
</tr>
@empty<tr><td colspan="4">No employee withholding tax remittances recorded. An approved payroll accrual and independently approved tax policy are required first.</td></tr>
@endforelse
</tbody></table></div>
{{ $records->links() }}
</section>
@endsection
