@extends('layouts.kanozay')
@section('content')
<style>
.qc-sheet{max-width:850px;margin:auto;background:white;border:1px solid #dce6ef;border-radius:10px;padding:30px}
.qc-sheet table{width:100%;border-collapse:collapse;font-size:12px}
.qc-sheet th,.qc-sheet td{padding:11px;border-bottom:1px solid #dce6ef}
@media print{@page{size:A4;margin:12mm}.heading,.top,.rail,.sidebar,.no-print{display:none!important}.qc-sheet{max-width:none;border:0;padding:0}}
</style>
<div class="heading"><div><h1>Approved QC certificate {{ $sample->number }}</h1><p>Verified laboratory disposition</p></div><button class="btn" onclick="window.print()">Print / PDF</button></div>
<section class="qc-sheet">
<header style="display:flex;align-items:center;gap:20px;border-bottom:3px solid #1d7b55;padding-bottom:15px">
<img src="{{ asset('images/kanozay-logo.jpg') }}" style="width:125px" alt="Kanozay"><div><h2>Kanozay Foods Manufacturing Company</h2><strong>QUALITY CONTROL CERTIFICATE</strong></div></header>
<p><strong>Sample:</strong> {{ $sample->number }} · <strong>Collected:</strong> {{ $sample->collected_on }}</p>
@if($sample->source_type==='incoming_material')
<p><strong>Source GRN line:</strong> #{{ $sample->goods_receipt_line_id }}</p>
@else
<p><strong>Finished production batch ID:</strong> #{{ $sample->production_batch_id }}</p>
@endif
@if($legacyRequirements)<p style="color:#94572a"><strong>Historical evidence limitation:</strong> The original required test versions were not captured for this legacy sample. Listed criteria require source-document verification.</p>
@else <p>Test specifications shown below are the versions assigned at sample collection.</p>@endif
<p><strong>Source:</strong> {{ $sample->source_type }} · <strong>Disposition:</strong> APPROVED</p>
<table><thead><tr><th>Test / version</th><th>Approved limits</th><th>Measured</th><th>Outcome</th></tr></thead><tbody>
@foreach($specs as $spec)@php($result=$results->get($spec->id))
<tr><td>{{ $spec->test_name }} (v{{ $spec->version }})</td><td>{{ $spec->kind==='numeric'?($spec->minimum??'—').' to '.($spec->maximum??'—').' '.$spec->unit:'Pass / fail' }}</td>
<td>{{ $result?->observed_value??($result?->observation??'—') }}</td><td>{{ strtoupper($result?->outcome??'PENDING') }}</td></tr>
@endforeach</tbody></table>
<p><strong>Independent review:</strong> {{ $sample->review_notes }}</p>
<p><strong>Approved date:</strong> {{ $sample->reviewed_at }} · <strong>Reviewer ID:</strong> {{ $sample->reviewed_by }}</p>
<p style="margin-top:50px">Laboratory manager signature: __________________</p>
</section>
@endsection
