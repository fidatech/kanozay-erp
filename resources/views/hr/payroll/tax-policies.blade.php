@extends('layouts.kanozay')
@section('content')
<style>
.tax-panel{border:1px solid #dce8ef;border-radius:12px;padding:20px;background:white;margin-bottom:16px}
.tax-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.tax-grid label{display:grid;gap:5px;font-size:12px;color:#445f7e}
.tax-grid input,.tax-grid select,.tax-grid textarea{padding:10px;border:1px solid #d8e4ef;border-radius:7px;width:100%;box-sizing:border-box}
.tax-wrap{overflow:auto}.tax-panel table{min-width:900px;font-size:12px}
.tax-panel th,.tax-panel td{padding:10px}.tax-alert{padding:12px;background:#fff7e5;border-radius:8px;color:#7c5b21}
@media(max-width:900px){.tax-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Payroll Tax Policy & Reviewed Withholdings</h1><p>Editable statutory withholding rules, salary impact preview and independently approved application</p></div><a href="{{ route('workspace.page',['hr','payroll']) }}" class="btn btn-light">Payroll periods</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<p class="tax-alert">No tax rates are installed by default. Enter brackets only from Kanozay's confirmed legal/payroll instructions. A bracket computes fixed withholding plus a percentage of salary exceeding its lower threshold. All adjacent brackets must be continuous, starting at zero and ending with an open-ended bracket. Draft policies cannot affect payroll.</p>
@canany(['hr.update','administration.update'])
<section class="tax-panel"><h2>Register a draft tax policy</h2>
<form method="POST" action="{{ route('hr.payroll.tax-policies.store') }}">@csrf
<div class="tax-grid">
<label>Policy code<input name="code" required maxlength="60" placeholder="AFN-TAX-YYYY"></label>
<label>Policy title<input name="name" required maxlength="160"></label>
<label>Currency<select name="currency_id" required><option value="">Choose currency</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></label>
<label>Effective month (first day)<input name="effective_from" type="date" required></label>
<label>Taxable basis<select name="basis"><option value="base_salary">Monthly base salary snapshot</option><option value="salary_plus_allowances">Base salary plus recorded allowances</option></select></label>
<label>Withholding liability account<select name="liability_ledger_id" required><option value="">Choose a withholding liability</option>@foreach($liabilities as $l)<option value="{{ $l->id }}">{{ $l->code }} · {{ $l->name }}</option>@endforeach</select></label>
<label style="grid-column:1/-1">Source policy and calculation notes<textarea name="notes" minlength="10" maxlength="3000" required></textarea></label>
</div>
<h3>Marginal salary brackets</h3>
<p>Each bracket starts where the previous one ends. Upper bound is exclusive and can be blank only for the final bracket.</p>
<div class="tax-wrap"><table><thead><tr><th>Lower bound</th><th>Upper bound</th><th>Fixed amount at lower bound</th><th>Percentage above lower bound</th></tr></thead><tbody id="tax-brackets">
@for($i=0;$i<3;$i)<tr>
<td><input name="brackets[{{ $i }}][lower_bound]" inputmode="decimal" placeholder="{{ $i===0?'0':'' }}" @if($i===0) value="0" @endif required></td>
<td><input name="brackets[{{ $i }}][upper_bound]" inputmode="decimal" placeholder="Blank for final"></td>
<td><input name="brackets[{{ $i }}][fixed_tax]" inputmode="decimal" value="0" required></td>
<td><input name="brackets[{{ $i }}][marginal_percent]" inputmode="decimal" value="0" required></td>
</tr>@endfor
</tbody></table></div>
<p><button type="button" class="btn btn-light" onclick="kanozayAddBracket()">Add bracket row</button></p>
<button class="btn" type="submit">Save draft policy</button></form></section>
<script>
function kanozayAddBracket(){
 const box=document.getElementById('tax-brackets');const n=box.children.length;
 if(n>=20)return;
 const row=document.createElement('tr');
 for(const field of ['lower_bound','upper_bound','fixed_tax','marginal_percent']){
 const cell=document.createElement('td');const input=document.createElement('input');
 input.name='brackets['+n+']['+field+']';input.inputMode='decimal';input.required=field!=='upper_bound';
 if(field==='fixed_tax'||field==='marginal_percent')input.value='0';
 cell.appendChild(input);row.appendChild(cell);
 }box.appendChild(row);
}
</script>
@endcanany
<section class="tax-panel"><h2>Approved and draft policies</h2>
<div class="tax-wrap"><table><thead><tr><th>Policy</th><th>Currency</th><th>Effective</th><th>Basis</th><th>Bracket formula</th><th>State</th><th>Action</th></tr></thead><tbody>
@forelse($policies as $p)
<tr><td><strong>{{ $p->code }}</strong><br>{{ $p->name }}</td><td>{{ $p->currency?->code }}</td>
<td>{{ $p->effective_from?->toDateString() }}</td><td>{{ str_replace('_',' ',$p->basis) }}</td>
<td>@foreach($p->brackets as $b)<div>{{ $b->lower_bound }}–{{ $b->upper_bound??'∞' }}: {{ $b->fixed_tax }} + {{ $b->marginal_percent }}% above {{ $b->excess_above }}</div>@endforeach</td>
<td>{{ ucfirst($p->status) }}@if($p->approval_reference)<div>{{ $p->approval_reference }}</div>@endif</td>
<td>@if($p->status==='draft')@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('hr.payroll.tax-policies.approve',$p) }}" style="display:grid;gap:8px">@csrf
<input name="approval_reference" minlength="3" maxlength="180" required placeholder="Independent approval reference"><button class="btn btn-light">Approve</button>
</form>@endcanany
@else<a href="{{ route('hr.payroll.tax-policies.index',['policy_id'=>$p->id]) }}">Approved policy</a>@endif</td></tr>
@empty<tr><td colspan="7">No payroll withholding policy registered.</td></tr>@endforelse
</tbody></table></div>{{ $policies->links() }}</section>
<section class="tax-panel"><h2>Preview a payroll period before applying</h2>
<form method="GET" action="{{ route('hr.payroll.tax-policies.index') }}" class="tax-grid">
<label>Approved policy<select name="policy_id" required><option value="">Choose</option>
@foreach($policies as $p)@if($p->status==='approved')<option value="{{ $p->id }}" @selected($selected?->id===$p->id)>{{ $p->code }}</option>@endif @endforeach
</select></label>
<label>Draft payroll period<select name="period_id" required><option value="">Choose</option>
@foreach($periods as $pr)<option value="{{ $pr->id }}" @selected($period?->id===$pr->id)>{{ $pr->period_start->format('Y-m') }} · {{ $pr->currency?->code }} ({{ $pr->status }})</option>@endforeach
</select></label><div><button class="btn btn-light">Calculate preview</button></div>
</form>
@if($period && $selected && $preview)
<h3>{{ $selected->code }} · {{ $period->period_start->format('Y-m') }}</h3>
<div class="tax-wrap"><table><thead><tr><th>Employee number</th><th>Employee</th><th>Taxable base</th><th>Calculated withholding</th><th>Current payable (before application)</th></tr></thead><tbody>
@foreach($preview as $row)<tr><td>{{ $row['number'] }}</td><td>{{ $row['employee'] }}</td><td>{{ $row['gross'] }}</td><td>{{ $row['tax'] }}</td><td>{{ $row['net'] }}</td></tr>@endforeach
</tbody></table></div>
@if($period->status==='draft')@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('hr.payroll.tax-policies.apply',$selected) }}" style="margin-top:15px">@csrf
<input type="hidden" name="payroll_period_id" value="{{ $period->id }}">
<label><input type="checkbox" name="confirm_review" required value="1"> I reviewed this policy, currency and salary calculations against the authorized payroll source.</label>
<button class="btn">Apply approved withholding to draft</button></form>@endcanany
@endif
@endif
</section>
@endsection