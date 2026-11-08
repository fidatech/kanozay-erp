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
<div class="heading"><div><h1>{{ $pageTitle }} · Laboratory QC register</h1><p>Approved test criteria, source-linked samples and independent quality decisions</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-light" href="{{ route('inventory.quality-holds.index') }}">Incoming QC holds</a><a class="btn btn-light" href="{{ route('workspace.page',['manufacturing','orders']) }}">Production batches</a></div></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
@if($page==='specifications')
@canany(['laboratory.update','administration.update'])
<section class="qc-panel"><h2>Approve a versioned test specification</h2>
<form class="qc-form" method="POST" action="{{ route('laboratory.specifications.store') }}">@csrf
<label>Subject<select name="subject_type"><option value="material">Raw material</option><option value="finished_good">Finished good</option></select></label>
<label>Subject item<select name="subject_id" id="quality-subject-id" required>
<option value="">Select a material or finished product</option>
@foreach($materials as $material)<option value="{{ $material->id }}" data-subject="material">{{ $material->sku }} · {{ $material->name }}</option>@endforeach
@foreach($goods as $good)<option value="{{ $good->id }}" data-subject="finished_good">{{ $good->sku }} · {{ $good->name }}</option>@endforeach
</select></label>
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
@if($page==='nonconformances')
<section class="qc-panel">
<h2>Nonconformance investigations and corrective action</h2>
<p class="qc-note">A nonconformance investigation records a quality deviation, corrective action and independent verification. Opening or closing one does not release a rejected sample or change physical stock. For suspicious incoming materials, also place a manual hold in <a href="{{ route('inventory.quality-holds.index') }}">Incoming QC holds</a>.</p>
@canany(['laboratory.create','laboratory.update','administration.update'])
<h3>Open a nonconformance</h3>
<form action="{{ route('laboratory.nonconformances.store') }}" method="POST" class="qc-form">
@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Laboratory sample<select name="quality_sample_id">
<option value="">No sample / select a lot</option>
@foreach($ncrRecentSamples as $registered)
<option value="{{ $registered->id }}">{{ $registered->number }} · {{ $registered->source_type }} · {{ $registered->status }}</option>
@endforeach
</select></label>
<label>Incoming FIFO lot (optional)<select name="inventory_cost_layer_id">
<option value="">No incoming lot</option>
@foreach($ncrLots as $layer)
<option value="{{ $layer->id }}">Lot #{{ $layer->id }} · {{ $layer->material?->sku }} · GRN line #{{ $layer->source_id }}</option>
@endforeach
</select></label>
<label>Severity<select name="severity" required>
<option value="minor">Minor</option><option value="major">Major</option><option value="critical">Critical</option>
</select></label>
<label>Opened on<input type="date" name="opened_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></label>
<label>Target review date<input type="date" name="due_on"></label>
<label style="grid-column:span 2">Observed deviation / incident
<textarea name="description" rows="2" minlength="15" maxlength="5000" required placeholder="Describe the actual product or process deviation with source evidence"></textarea></label>
<button class="btn" type="submit">Open NCR</button>
</form>
@endcanany
</section>
<section class="qc-panel">
<h2>Investigations and independent closures</h2>
<div class="qc-wrap"><table style="min-width:1100px"><thead>
<tr><th>NCR / severity</th><th>Source</th><th>Raised</th><th>Due</th><th>Status</th><th>Investigation / verification</th></tr></thead><tbody>
@forelse($nonconformances as $record)
<tr>
<td><strong>{{ $record->number }}</strong><p>{{ ucfirst($record->severity) }}</p></td>
<td>{{ $record->sample_number??'No sample' }}<br>
@if($record->inventory_cost_layer_id)
Lot #{{ $record->inventory_cost_layer_id }} · {{ $record->material_sku }}
@endif
<p>{{ $record->description }}</p></td>
<td>{{ $record->opened_on }}</td>
<td>@if($record->due_on && $record->status!=='closed' && $record->due_on<now()->toDateString())
<strong style="color:#a63131">{{ $record->due_on }} · Overdue</strong>
@else{{ $record->due_on??'Not specified' }}@endif</td>
<td>{{ ucfirst($record->status) }}</td>
<td>
@if($record->root_cause)<strong>Root cause:</strong> {{ $record->root_cause }}<br>
<strong>Corrective action:</strong> {{ $record->corrective_action }}<br>@endif
@if($record->status==='closed')
<strong>Independent verification:</strong> {{ $record->verification_evidence }}
<br><small>Closed {{ $record->closed_at }}</small>
@else
@canany(['laboratory.create','laboratory.update','administration.update'])
<details style="margin:6px 0"><summary>Record corrective investigation</summary>
<form action="{{ route('laboratory.nonconformances.investigate',$record->id) }}" method="POST" style="display:grid;gap:7px;padding:8px">
@csrf
<textarea name="root_cause" required minlength="15" maxlength="5000" placeholder="Verified root cause">{{ $record->root_cause }}</textarea>
<textarea name="corrective_action" required minlength="15" maxlength="5000" placeholder="Corrective action and supporting evidence">{{ $record->corrective_action }}</textarea>
<button class="btn" type="submit">Save investigation</button>
</form></details>
@endcanany
@if($record->status==='investigating')
@canany(['laboratory.update','administration.update'])
<details style="margin:6px 0"><summary>Independent closure</summary>
<form action="{{ route('laboratory.nonconformances.close',$record->id) }}" method="POST" style="display:grid;gap:7px;padding:8px">
@csrf
<textarea name="verification_evidence" minlength="15" maxlength="5000" required placeholder="Evidence corrective action was verified independently"></textarea>
<button type="submit" class="btn btn-light">Verify and close NCR</button>
</form></details>
@endcanany
@endif
@endif
</td></tr>
@empty<tr><td colspan="6">No nonconformance cases recorded.</td></tr>@endforelse
</tbody></table></div>
<p class="muted">Showing up to 150 recent investigations, with open investigations listed before closed records. Sample and inventory disposition remain independently controlled.</p>
</section>
@endif
@canany(['laboratory.create','administration.update'])
<section class="qc-panel"><h2>Register a source-linked sample</h2>
<form class="qc-form" method="POST" action="{{ route('laboratory.samples.store') }}">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Source type<select name="source_type"><option value="finished_batch">Finished production batch</option><option value="incoming_material">Incoming goods receipt</option></select></label>
<label>Production batch<select name="production_batch_id" id="quality-batch-id"><option value="">None</option>@foreach($batches as $b)<option value="{{ $b->id }}">{{ $b->batch_number }} · {{ $b->finishedGood?->name }}</option>@endforeach</select></label>
<label>GRN line<select name="goods_receipt_line_id" id="quality-grn-id"><option value="">None</option>@foreach($receipts as $line)<option value="{{ $line->id }}">{{ $line->receipt?->number }} · {{ $line->material?->sku }}</option>@endforeach</select></label>
<label>Collected on<input type="date" name="collected_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></label>
<label style="grid-column:span 2">Notes<input name="notes" maxlength="2500"></label>
<button class="btn">Register sample</button></form>
<p class="qc-note">For finished-batch QC, choose only a production batch. For incoming-material QC, choose only a posted GRN line. The current approved specification versions are frozen when the sample is registered. Pending or rejected incoming samples block that source lot in inventory and production; a separately authorized manual hold can also restrict an already approved lot.</p></section>
@endcanany
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const subject=document.querySelector('select[name="subject_type"]');
 const item=document.getElementById('quality-subject-id');
 const source=document.querySelector('select[name="source_type"]');
 const batch=document.getElementById('quality-batch-id');
 const grn=document.getElementById('quality-grn-id');
 if(subject&&item){
  const update=()=>{
   [...item.options].forEach(option=>{
    if(!option.value)return;
    const matches=option.dataset.subject===subject.value;
    option.disabled=!matches;
    option.hidden=!matches;
   });
   if(item.selectedOptions[0]?.disabled)item.value='';
   if(window.jQuery?.fn?.select2)window.jQuery(item).trigger('change.select2');
  };
  subject.addEventListener('change',update);update();
 }
 if(source&&batch&&grn){
  const update=()=>{
   const incoming=source.value==='incoming_material';
   batch.disabled=incoming;
   grn.disabled=!incoming;
   if(incoming)batch.value='';else grn.value='';
   if(window.jQuery?.fn?.select2){
    window.jQuery(batch).trigger('change.select2');
    window.jQuery(grn).trigger('change.select2');
   }
  };
  source.addEventListener('change',update);update();
 }
});
</script>
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
