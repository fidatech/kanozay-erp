@extends('layouts.kanozay')
@section('content')
<style>
.qc-panel{padding:20px;border:1px solid #dce7f1;background:white;border-radius:12px;margin-bottom:15px}
.qc-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end}
.qc-form label{font-size:12px;color:#41627d;display:grid;gap:6px}
.qc-form input,.qc-form select,.qc-form textarea{width:100%;min-width:0;box-sizing:border-box;padding:10px;border:1px solid #d6e3ed;border-radius:7px}
.qc-panel .qc-wrap{overflow:auto}.qc-panel table{min-width:790px;font-size:12px}
.qc-panel th,.qc-panel td{padding:11px}
.qc-note{padding:12px;background:#fff8e7;color:#765c2c;border-radius:8px;font-size:12px;line-height:1.7}
@media(max-width:880px){.qc-form{grid-template-columns:1fr}}
</style>
<div class="heading"><div><h1>{{ $pageTitle }} · Laboratory QC register</h1><p>Approved test criteria, source-linked samples and independent quality decisions</p></div><a class="btn btn-light" href="{{ route('workspace.page',['manufacturing','orders']) }}">Production batches</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@if($page==='specifications')
@canany(['laboratory.update','administration.update'])
<section class="qc-panel"><h2>Approve a versioned test specification</h2>
<form class="qc-form" method="POST" action="{{ route('laboratory.specifications.store') }}">@csrf
<label>Subject<select name="subject_type"><option value="material">Raw material</option><option value="finished_good">Finished good</option></select></label>
<label>Subject ID<input type="number" name="subject_id" min="1" required placeholder="See subject references below"></label>
<label>Test name<input name="test_name" maxlength="120" required></label>
<label>Method<input name="method" maxlength="180"></label>
<label>Test format<select name="kind"><option value="numeric">Numeric limits</option><option value="qualitative">Qualitative pass / fail</option></select></label>
<label>Unit<input name="unit" maxlength="40"></label>
<label>Minimum<input name="minimum" inputmode="decimal"></label>
<label>Maximum<input name="maximum" inputmode="decimal"></label>
<button class="btn">Approve specification</button></form>
<details style="margin-top:15px"><summary>Subject references</summary>
<p class="muted"><strong>Materials:</strong> @foreach($materials as $m) #{{ $m->id }} {{ $m->sku }} · @endforeach</p>
<p class="muted"><strong>Products:</strong> @foreach($goods as $g) #{{ $g->id }} {{ $g->sku }} · @endforeach</p>
</details>
</section>
@endcanany
@endif
<section class="qc-panel"><h2>Active quality specifications</h2>
<div class="qc-wrap"><table><thead><tr><th>ID</th><th>Subject</th><th>Test</th><th>Method</th><th>Limits</th><th>Version</th></tr></thead><tbody>
@forelse($specs as $s)<tr><td>{{ $s->id }}</td><td>{{ $s->subject_type }} #{{ $s->subject_id }}</td><td>{{ $s->test_name }}</td><td>{{ $s->method??'—' }}</td><td>{{ $s->kind==='numeric'?($s->minimum??'—').' to '.($s->maximum??'—').' '.$s->unit:'Pass / fail' }}</td><td>{{ $s->version }}</td></tr>
@empty<tr><td colspan="6">No approved quality specifications.</td></tr>@endforelse
</tbody></table></div></section>
@canany(['laboratory.create','administration.update'])
<section class="qc-panel"><h2>Register a source-linked sample</h2>
<form class="qc-form" method="POST" action="{{ route('laboratory.samples.store') }}">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Source type<select name="source_type"><option value="finished_batch">Finished production batch</option><option value="incoming_material">Incoming goods receipt</option></select></label>
<label>Production batch<select name="production_batch_id"><option value="">None</option>@foreach($batches as $b)<option value="{{ $b->id }}">{{ $b->batch_number }} · {{ $b->finishedGood?->name }}</option>@endforeach</select></label>
<label>GRN line<select name="goods_receipt_line_id"><option value="">None</option>@foreach($receipts as $line)<option value="{{ $line->id }}">{{ $line->receipt?->number }} · {{ $line->material?->sku }}</option>@endforeach</select></label>
<label>Collected on<input type="date" name="collected_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label style="grid-column:span 2">Notes<input name="notes" maxlength="2500"></label>
<button class="btn">Register sample</button></form>
<p class="qc-note">For finished-batch QC, select only a production batch. For incoming-material QC, select only a GRN line. Approved specifications must exist for that subject. Incoming-material results are recorded but do not yet quarantine specific inventory lots; do not issue rejected material pending warehouse reconciliation.</p></section>
@endcanany
<section class="qc-panel"><h2>Sample register and outcomes</h2>
<div class="qc-wrap"><table><thead><tr><th>Reference</th><th>Source</th><th>Collection date</th><th>Review status</th><th>Test outcomes</th><th>Record</th></tr></thead><tbody>
@forelse($samples as $s)<tr><td>{{ $s->number }}</td>
<td>{{ $s->source_type==='finished_batch'?($s->batch_number.' · '.$s->finished_good_name):($s->material_name??'Receipt line') }}</td>
<td>{{ $s->collected_on }}</td><td>{{ ucfirst(str_replace('_',' ',$s->status)) }}</td>
<td>@foreach($results->get($s->id,collect()) as $res)
<div>{{ $res->test_name }}: {{ strtoupper($res->outcome) }}</div>@endforeach</td>
<td><a href="{{ route('laboratory.samples.show',$s->id) }}">Open / Review</a>
@if($s->status==='approved')<br><a href="{{ route('laboratory.samples.certificate',$s->id) }}">Print certificate</a>@endif</td></tr>
@empty<tr><td colspan="6">No matching quality samples recorded.</td></tr>@endforelse
</tbody></table></div>{{ $samples->links() }}</section>
@endsection
