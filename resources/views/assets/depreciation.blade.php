@extends('layouts.kanozay')
@section('content')
<style>
.depr-panel{padding:22px;border:1px solid #e0e8f0;border-radius:12px;background:white;margin-bottom:16px}
.depr-field{display:grid;gap:6px;font-size:12px;color:#345474;font-weight:700}
.depr-field input,.depr-field select{box-sizing:border-box;width:100%;padding:11px;border:1px solid #d6e4f0;border-radius:8px;min-height:42px}
.depr-layout{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.depr-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;align-items:end}
.depr-panel table{font-size:12px;min-width:760px}
.depr-note{font-size:12px;color:#68809a;line-height:1.65}
@media(max-width:1000px){.depr-layout{grid-template-columns:1fr}}
@media(max-width:570px){.depr-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-calculator"></i> Fixed asset depreciation</h1><p>Finance-approved straight-line monthly accounting by asset and source currency.</p></div><a class="btn btn-light" href="{{ route('workspace.page',['assets','register']) }}">Asset register</a></div>
@if(session('success'))<div class="depr-panel" style="color:#16704e">{{ session('success') }}</div>@endif
@if($errors->any())<div class="depr-panel" style="color:#b22c25">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="depr-layout"><section class="depr-panel"><h2>Approved asset policies</h2>
<p class="depr-note">Use asset acquisition currency and approved expense/contra-asset ledger accounts. Policy changes are forbidden after any depreciation posting.</p>
<div class="table-wrap"><table><thead><tr><th>Asset</th><th>Acquisition</th><th>Currency</th><th>Life</th><th>Salvage</th><th>Ledger accounts</th></tr></thead><tbody>
@forelse($assets as $asset)
@php($policy=$policies->get($asset->id))
<tr><td><a href="{{ route('assets.show',$asset) }}">{{ $asset->asset_tag }}</a><br>{{ $asset->name }}</td><td>{{ $asset->acquisition_cost }}</td><td>{{ $asset->currency?->code }}</td><td>{{ $policy?->useful_life_months??'Not configured' }}</td><td>{{ $policy?->salvage_value??'—' }}</td><td>{{ $policy?->expenseLedger?->code??'—' }} / {{ $policy?->accumulatedLedger?->code??'—' }}</td></tr>
@empty<tr><td colspan="6">No registered assets.</td></tr>@endforelse
</tbody></table></div></section>
@canany(['finance.update','administration.update'])
<section class="depr-panel"><h2>Approve depreciation policy</h2><form method="POST" id="depr-policy" class="depr-form">@csrf
<label class="depr-field">Asset<select name="asset_id" required onchange="this.form.action='{{ url('/assets') }}/'+this.value+'/depreciation-policy';">
<option value="">Choose active asset</option>@foreach($assets as $asset)@if($asset->status==='active')<option value="{{ $asset->id }}">{{ $asset->asset_tag }} - {{ $asset->name }}</option>@endif @endforeach
</select></label>
<label class="depr-field">Useful life (months)<input type="number" name="useful_life_months" min="1" max="1200" value="60" required></label>
<label class="depr-field">Salvage value<input name="salvage_value" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,4})?" value="0" required></label>
<label class="depr-field">Start month<input type="month" name="start_month" value="{{ now()->subMonth()->format('Y-m') }}" required></label>
<label class="depr-field">Expense account<select name="expense_ledger_id" required><option value="">Choose</option>
@foreach($expenseAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>@endforeach
</select></label>
<label class="depr-field">Accumulated depreciation (asset) account<select name="accumulated_ledger_id" required><option value="">Choose</option>
@foreach($assetAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>@endforeach
</select></label>
<div><button class="btn" type="submit" onclick="return this.form.action.includes('/depreciation-policy')">Approve policy</button></div>
</form>
<p class="depr-note">An acquisition reference is required when the asset is registered. Setting this policy does not post the asset acquisition entry; verify asset opening journals independently.</p></section>
@endcanany
</div>
@canany(['finance.update','administration.update'])
<section class="depr-panel"><h2>Post completed depreciation month</h2>
<form method="POST" class="depr-form" id="depr-post">@csrf
<label class="depr-field">Asset with approved policy<select required onchange="this.form.action='{{ url('/assets') }}/'+this.value+'/depreciation-post';">
<option value="">Choose</option>@foreach($assets as $asset)@if($policies->has($asset->id) && $asset->status==='active')<option value="{{ $asset->id }}">{{ $asset->asset_tag }} {{ $asset->name }}</option>@endif @endforeach
</select></label>
<label class="depr-field">Completed month<input name="month" type="month" max="{{ now()->subMonthNoOverflow()->format('Y-m') }}" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" required></label>
<div><button class="btn" type="submit" onclick="return this.form.action.includes('/depreciation-post')">Post to General Ledger</button></div>
</form>
<p class="depr-note">Months must be posted sequentially. The final month adjusts rounding so total depreciation equals acquisition cost minus salvage value. Each month posts once.</p></section>
@endcanany
<section class="depr-panel"><h2>Posted depreciation journal register</h2>
<div class="table-wrap"><table><thead><tr><th>Asset</th><th>Period</th><th>Amount</th><th>Currency</th><th>Posted journal</th></tr></thead><tbody>
@forelse($entries as $entry)<tr><td>{{ $entry->asset?->asset_tag }}</td><td>{{ $entry->period_month?->format('Y-m') }}</td><td>{{ $entry->amount }}</td><td>{{ $entry->asset?->currency?->code }}</td><td>{{ $entry->journal?->number }}</td></tr>
@empty<tr><td colspan="5" style="text-align:center;padding:28px">No depreciation has been posted.</td></tr>@endforelse
</tbody></table></div>{{ $entries->links() }}</section>
@endsection
