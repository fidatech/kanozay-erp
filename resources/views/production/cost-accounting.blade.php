@extends('layouts.kanozay')
@section('content')
<style>
.mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}
.mc-panel{background:#fff;border:1px solid #dce8f3;padding:19px;border-radius:12px;margin:16px 0}
.mc-panel h2{font-size:16px;margin:0 0 12px}
.mc-panel p{line-height:1.6}
.mc-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:11px;align-items:end}
.mc-form label{display:grid;gap:7px;font-size:12px;font-weight:650}
.mc-form input,.mc-form select,.mc-form textarea{min-height:40px;width:100%;border:1px solid #d8e3f0;padding:9px;border-radius:8px}
.mc-table{overflow:auto}.mc-table table{min-width:1060px}
.mc-table th,.mc-table td{padding:12px;text-align:start;vertical-align:top}
.mc-muted{font-size:12px;color:#627a92;line-height:1.7}
.mc-warning{background:#fff7e9;border:1px solid #f0d7a8;color:#805a2b;border-radius:9px;padding:13px;line-height:1.7}
.mc-posted{color:#147854;font-weight:700}
.mc-pending{color:#a3602e;font-weight:650}
.mc-form details{min-width:280px}
.mc-form details summary{cursor:pointer}
</style>
<div class="heading">
<div><h1>Manufacturing cost accounting</h1>
<p>Original-currency FIFO inventory → WIP → finished goods → COGS</p></div>
<div class="actions">
<a class="btn btn-light" href="{{ route('manufacturing.cost-readiness.index') }}">Cost readiness</a>
<a class="btn btn-light" href="{{ route('reports.manufacturing-financial.index') }}">Inventory &amp; COGS reconciliation</a>
<a class="btn btn-light" href="{{ route('workspace.page',['manufacturing','costing']) }}">Overhead approvals</a>
</div></div>
@if(session('success'))<section class="mc-panel" role="status">{{ session('success') }}</section>@endif
@if($errors->any())<section class="mc-panel mc-warning" role="alert">{{ $errors->first() }}</section>@endif
<p class="mc-warning">This workflow changes posted accounting balances. Only finance-approved original-currency mappings can be used. Material sources must be FIFO-traced, fully billed in the selected raw-inventory ledger, and free of unreconciled landed costs. Labor/overhead is reclassified only from a separate approved, already-posted expense debit. It never invents an exchange rate, cost, supplier invoice, production input or accounting policy. Legacy sales before capitalization require a separately reviewed historic reconciliation.</p>
<section class="mc-panel">
<h2>Finance-approved cost ledger policies</h2>
<p class="mc-muted">Four independent ledger accounts are required for every original costing currency. Drafts are approved by a different finance reviewer and cannot be silently edited after approval.</p>
<div class="mc-table"><table><thead><tr><th>Currency</th><th>Raw material / packaging</th><th>Work in progress</th><th>Finished goods</th><th>Cost of goods sold</th><th>Authorization</th><th>Status</th></tr></thead><tbody>
@forelse($policies as $policy)
<tr><td>{{ $policy->currency_code }}</td>
<td>#{{ $policy->material_inventory_ledger_id }}</td>
<td>#{{ $policy->work_in_progress_ledger_id }}</td>
<td>#{{ $policy->finished_inventory_ledger_id }}</td>
<td>#{{ $policy->cogs_ledger_id }}</td>
<td>{{ $policy->authorization_reference }}</td>
<td>
@if($policy->status==='approved')
<span class="mc-posted">Approved</span>
@else
<span class="mc-pending">Awaiting independent finance approval</span>
@canany(['finance.update','administration.update'])
<details><summary>Approve policy</summary>
<form method="POST" action="{{ route('manufacturing.cost-accounting.policies.approve',$policy->id) }}" style="display:grid;gap:8px;min-width:230px">
@csrf
<input type="text" name="approval_reference" required minlength="4" maxlength="180" placeholder="Signed approval record">
<button class="btn" type="submit">Approve GL policy</button>
</form></details>
@endcanany
@endif
</td></tr>
@empty<tr><td colspan="7">No manufacturing cost ledger mappings recorded. Finance must approve a currency-specific policy before capitalization.</td></tr>@endforelse
</tbody></table></div>
@canany(['finance.update','administration.update'])
<details style="margin-top:15px"><summary>Define a new original-currency cost policy</summary>
<form method="POST" action="{{ route('manufacturing.cost-accounting.policies.store') }}" class="mc-form" style="margin-top:14px">
@csrf
<label>Original costing currency<select name="currency_id" required><option value="">Choose currency</option>
@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach
</select></label>
@foreach(['material_inventory_ledger_id'=>'Raw material and packaging inventory','work_in_progress_ledger_id'=>'Manufacturing WIP asset','finished_inventory_ledger_id'=>'Finished goods inventory asset'] as $key=>$name)
<label>{{ $name }}<select name="{{ $key }}" required><option value="">Select original GL asset ledger</option>
@foreach($assets as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
</select></label>
@endforeach
<label>Cost of goods sold expense ledger<select name="cogs_ledger_id" required><option value="">Select GL expense ledger</option>
@foreach($expenses as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></label>
<label>Policy / board authorization<input name="authorization_reference" required minlength="4" maxlength="180" placeholder="Authorized GL mapping document"></label>
<label style="grid-column:1/-1">Source accounting policy evidence<textarea name="notes" minlength="15" maxlength="3000" required rows="2" placeholder="Identify the approved chart-of-accounts mapping and original-currency treatment"></textarea></label>
<div><button class="btn" type="submit">Submit for independent approval</button></div>
</form></details>
@endcanany
</section>
<section class="mc-panel">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
<div><h2>Production batch GL capitalization</h2><p class="mc-muted">Cost-readiness snapshots are advisory until their original supplier and overhead journal sources are verified during posting. Never capitalize a batch on the basis of a draft cost estimate.</p></div>
<form method="GET" action="{{ route('manufacturing.cost-accounting.index') }}" style="display:flex;align-items:center;gap:8px">
<select name="status"><option value="">All batch states</option>
@foreach(['released'=>'Released','qc_approved'=>'QC approved','pending_qc'=>'Pending QC','qc_rejected'=>'QC rejected'] as $status=>$label)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $label }}</option>@endforeach
</select><button class="btn btn-light" type="submit">Filter</button></form>
</div>
<div class="mc-table"><table>
<thead><tr><th>Batch and product</th><th>Accepted packages</th><th>Original currency</th><th>Material cost</th><th>Approved overhead</th><th>Cost per package (advisory)</th><th>GL posting status</th><th>Action</th></tr></thead><tbody>
@forelse($rows as $row)
@php($record=$posted->get($row['batch_id']))
<tr>
<td><strong>{{ $row['batch'] }}</strong><div>{{ $row['product'] }}</div><small>{{ ucfirst(str_replace('_',' ',$row['status'])) }}</small></td>
<td>{{ number_format((int)$row['packages']) }}</td>
<td>{{ $row['currency'] }}</td>
<td>{{ $row['material_cost']??'Unvalued' }}</td>
<td>{{ $row['approved_overhead'] }}</td>
<td>{{ $row['cost_per_package']??'Not verifiable' }}</td>
<td>@if($record)
<strong class="mc-posted">Posted · {{ $record->accounting_date }}</strong>
<div class="mc-muted">{{ $record->total_amount }} in {{ $row['currency'] }}
 · WIP {{ $record->issue_journal_number }}
 · Finished {{ $record->capital_journal_number }}</div>
@elseif(!$row['ready'])<span class="mc-pending">{{ $row['warning'] }}</span>
@else <span class="mc-pending">Source-book reconciliation pending</span> @endif</td>
<td>
@if($record)
<span class="mc-muted">Original WIP and asset journals recorded</span>
@elseif($row['ready'])
@canany(['finance.update','administration.update'])
<details><summary>Review and capitalize</summary>
<form method="POST" action="{{ route('manufacturing.cost-accounting.batches.capitalize',$row['batch_id']) }}" style="display:grid;gap:8px;min-width:235px;margin-top:8px">
@csrf
<label>Date<input type="date" name="accounting_date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
<label>Signed reconciliation<input name="approval_reference" minlength="4" maxlength="180" required placeholder="Financial acceptance reference"></label>
<label>Source record assessment<textarea name="reconciliation_notes" minlength="20" maxlength="3000" required placeholder="Confirm original GRN/supplier inventory ledger, FIFO costs and approved overhead"></textarea></label>
<label style="font-size:12px"><input name="confirm_source_ledgers" type="checkbox" required value="1"> Verify source GL and original currency before posting</label>
<button class="btn" type="submit" onclick="return confirm('Post two balanced journals moving verified material and approved overhead through WIP into finished-goods inventory?')">Post source-backed capitalization</button>
</form></details>
@else <span class="mc-muted">Finance permission required</span> @endcanany
@else<span class="mc-muted">Resolve source cost gaps first</span>@endif
</td></tr>
@empty<tr><td colspan="8">No production batches for this filter.</td></tr>@endforelse
</tbody></table></div>
{{ $batches->links() }}
</section>
@endsection
