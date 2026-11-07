@extends('layouts.kanozay')
@section('content')
<style>
.co-panel{padding:20px;border-radius:12px;background:#fff;border:1px solid #dbe7f2;margin:14px 0}
.co-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:end;gap:12px}
.co-grid label{display:grid;gap:6px;font-size:12px;color:#3f6080}
.co-grid input,.co-grid select,.co-grid textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d9e3ef;border-radius:8px}
.co-wrap{overflow:auto}.co-panel table{min-width:800px;font-size:12px}.co-panel th,.co-panel td{padding:11px}
.co-notice{padding:12px;border-radius:8px;background:#fff8e9;color:#816329;font-size:12px;line-height:1.65}
@media(max-width:860px){.co-grid{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>Production Costing</h1><p>Material valuation and independently reviewed overhead allocations by batch and currency</p></div><a class="btn btn-light" href="{{ route('production.orders.index') }}">Production orders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<p><a class="btn btn-light" href="{{ route('manufacturing.cost-readiness.index') }}">View batch cost readiness and indicative package costing</a></p>
<div class="co-notice">Overhead allocation uses amounts already posted to an expense journal. It never posts a second expense or manufactures missing material values. Cost snapshots are separated by currency, and batches containing unvalued materials remain incomplete until purchase-source evidence is reconciled. Finance approval and source journal availability are required.</div>
<section class="co-panel"><h2>Batch material-cost ledger</h2><div class="co-wrap"><table><thead><tr><th>Batch</th><th>Product</th><th>Currency</th><th>Valued material cost</th><th>Consumed quantity</th><th>Unvalued quantity</th></tr></thead><tbody>
@forelse($materialCosts as $c)
<tr><td>{{ $c->batch_number }}</td><td>{{ $c->product_name }}</td><td>{{ $c->currency_code?:'Unassigned' }}</td>
<td>{{ $c->material_cost??'Unvalued' }}</td><td>{{ $c->consumed_quantity }}</td><td>{{ $c->unvalued_quantity }}</td></tr>
@empty<tr><td colspan="6">No production batch costs calculated yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="co-panel"><h2>Approved operating overhead by batch</h2>
<div class="co-wrap"><table><thead><tr><th>Batch ID</th><th>Currency</th><th>Approved journal-backed overhead</th></tr></thead><tbody>
@forelse($byBatch as $group)<tr><td>{{ $group->production_batch_id }}</td><td>{{ $group->currency_code }}</td><td>{{ $group->total }}</td></tr>
@empty<tr><td colspan="3">No overhead approved.</td></tr>@endforelse
</tbody></table></div></section>
@if(auth()->user()->can('administration.update')||(auth()->user()->can('manufacturing.update')&&auth()->user()->can('finance.view')))
<section class="co-panel"><h2>Submit a source-backed cost allocation</h2>
<form method="POST" action="{{ route('manufacturing.overhead.store') }}" class="co-grid">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Production batch<select name="production_batch_id" required><option value="">Select batch</option>
@foreach($batches as $b)@if($b->status!=='rejected')<option value="{{ $b->id }}">{{ $b->batch_number }} · {{ $b->finishedGood?->name }}</option>@endif @endforeach</select></label>
<label>Posted expense journal line<select name="source_journal_line_id" required><option value="">Select a source expense debit</option>
@foreach($expenseLines as $line)<option value="{{ $line->id }}">{{ $line->number }} · {{ $line->ledger_code }} · {{ $line->currency_code }} {{ $line->debit }}</option>@endforeach</select></label>
<label>Cost category<select name="category"><option value="labor">Labor</option><option value="utilities">Utilities</option><option value="energy">Energy</option><option value="quality">Quality</option><option value="equipment">Equipment</option><option value="packaging">Packaging</option><option value="other">Other</option></select></label>
<label>Allocate amount<input name="amount" inputmode="decimal" required></label>
<label>Original approved expense reference<input name="source_reference" minlength="3" maxlength="180" required></label>
<label>Notes<textarea name="notes" rows="1" maxlength="3000"></textarea></label>
<button class="btn" type="submit">Submit for independent Finance review</button>
</form></section>
@endif
<section class="co-panel"><h2>Reviewed and pending overhead allocations</h2>
<div class="co-wrap"><table><thead><tr><th>Allocation</th><th>Batch</th><th>Category</th><th>Currency</th><th>Amount</th><th>Source journal</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($allocations as $a)<tr><td>{{ $a->number }}</td><td>{{ $a->batch_number }}</td><td>{{ ucfirst($a->category) }}</td><td>{{ $a->currency_code }}</td><td>{{ $a->amount }}</td><td>{{ $a->journal_number }}<br><small>{{ $a->source_reference }}</small></td><td>{{ ucfirst($a->status) }}</td><td>
@if($a->status==='submitted')
@canany(['finance.update','administration.update'])
<form method="POST" action="{{ route('manufacturing.overhead.decide',$a->id) }}" style="display:grid;gap:5px">@csrf
<input name="review_note" minlength="5" maxlength="2000" placeholder="Review explanation" required>
<div style="display:flex;gap:5px"><button class="btn btn-secondary" name="decision" value="approved">Approve</button><button class="btn btn-light" name="decision" value="rejected">Reject</button></div></form>
@endcanany
@else<small>{{ $a->review_note??'Reviewed' }}</small>@endif</td></tr>
@empty<tr><td colspan="8">No journal-backed overhead records.</td></tr>@endforelse
</tbody></table></div>{{ $allocations->links() }}</section>
@endsection
