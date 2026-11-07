@extends('layouts.kanozay')
@section('content')
<style>
.qs-panel{background:white;border:1px solid #dce7f2;border-radius:10px;padding:20px;margin:15px 0}
.qs-panel label{display:grid;gap:6px;font-size:12px}
.qs-panel input,.qs-panel select,.qs-panel textarea{padding:10px;border:1px solid #d5e3ef;border-radius:8px;min-width:200px}
.qs-panel table{width:100%;font-size:12px}.qs-panel th,.qs-panel td{padding:12px}
.qs-form{display:flex;flex-wrap:wrap;gap:12px;align-items:end}
</style>
<div class="heading"><div><h1>Laboratory sample {{ $sample->number }}</h1><p>Status {{ strtoupper($sample->status) }} · Collected {{ $sample->collected_on }}</p></div><a class="btn btn-light" href="{{ route('workspace.page',['laboratory','samples']) }}">Sample register</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ',$errors->all()) }}</p>@endif
<div class="qs-panel"><h2>Required specifications and recorded results</h2><table><thead><tr><th>Test</th><th>Criteria</th><th>Observation</th><th>Result</th></tr></thead><tbody>
@foreach($specs as $spec)<tr><td>{{ $spec->test_name }}</td><td>{{ $spec->kind==='numeric' ? ($spec->minimum??'—').' to '.($spec->maximum??'—').' '.$spec->unit : 'Pass / fail' }}</td>
@php($result=$results->get($spec->id))
<td>{{ $result?->observed_value??($result?->observation??'—') }}</td><td>{{ $result?->outcome??'Pending' }}</td></tr>@endforeach
</tbody></table></div>
@if(in_array($sample->status,['registered','in_testing'],true))
@canany(['laboratory.create','administration.update'])
<div class="qs-panel"><h2>Record an immutable laboratory result</h2>
<form class="qs-form" method="POST" action="{{ route('laboratory.samples.result',$sample->id) }}">@csrf
<label>Specification<select name="quality_specification_id" required><option value="">Choose untested</option>@foreach($specs as $spec)@if(!$results->has($spec->id))<option value="{{ $spec->id }}">{{ $spec->test_name }} ({{ $spec->kind }})</option>@endif @endforeach</select></label>
<label>Observed number<input name="observed_value" inputmode="decimal" placeholder="Numeric tests only"></label>
<label>Qualitative outcome<select name="outcome"><option value="">Numeric / not applicable</option><option value="pass">Pass</option><option value="fail">Fail</option></select></label>
<label>Observation<input name="observation" maxlength="3000"></label>
<button class="btn" type="submit">Save test</button></form></div>
@endcanany
@canany(['laboratory.update','administration.update'])
<div class="qs-panel"><h2>Independent final QC review</h2>
<form class="qs-form" method="POST" action="{{ route('laboratory.samples.review',$sample->id) }}">@csrf
<label>Decision<select name="decision" required><option value="approved">Approve all passing tests</option><option value="rejected">Reject sample</option></select></label>
<label>Review notes<input name="review_notes" minlength="5" maxlength="3000" required></label>
<button class="btn" type="submit">Record independent decision</button></form></div>
@endcanany
@else
<div class="qs-panel"><h2>Final review</h2><p>{{ $sample->review_notes }}</p>
@if($sample->status==='approved')<a class="btn" href="{{ route('laboratory.samples.certificate',$sample->id) }}">Approved QC certificate</a>@endif</div>
@endif
@endsection
