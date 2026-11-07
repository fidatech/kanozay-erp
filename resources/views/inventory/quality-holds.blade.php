@extends('layouts.kanozay')
@section('content')
<style>.qh-panel{padding:20px;background:white;border:1px solid #dce6f2;border-radius:12px}
.qh-panel table{min-width:850px;font-size:12px}.qh-panel th,.qh-panel td{padding:11px}
.qh-wrap{overflow:auto}.qh-note{padding:12px;border-radius:8px;background:#fff7e7;font-size:12px;color:#775523;line-height:1.7}</style>
<div class="heading"><div><h1>Receipt-lot QC holds</h1><p>Goods-receipt batches and their laboratory release status</p></div><a class="btn btn-light" href="{{ route('workspace.page',['laboratory','incoming']) }}">Incoming laboratory QC</a></div>
<section class="qh-panel">
<p class="qh-note">An incoming-material lot with an active QC specification cannot be issued for production until its latest sample is approved and covers the current tests. An explicitly rejected or unreviewed sample also holds the lot. Supplier returns can still be posted against the original receipt lot. Expiration-date tracking requires separately verified material expiration records.</p>
<div class="qh-wrap"><table><thead><tr><th>Raw material</th><th>Receipt</th><th>Lot valuation status</th><th>Remaining quantity</th><th>QC sample</th><th>Material release</th></tr></thead><tbody>
@forelse($rows as $r)
<tr><td>{{ $r['layer']->material?->sku }}<br>{{ $r['layer']->material?->name }}</td><td>{{ $r['receipt']?:'Unlinked source' }}</td>
<td>{{ $r['layer']->original_cost===null?'Unvalued':($r['layer']->currency_code??'Unknown') }}</td>
<td>{{ $r['layer']->remaining_quantity }}</td>
<td>@if($r['sample'])<a href="{{ route('laboratory.samples.show',$r['sample']->id) }}">{{ $r['sample']->number }}</a><br>{{ $r['sample']->status }}@else Not registered @endif</td>
<td>@if($r['held'])<strong style="color:#a23131">HELD — cannot issue</strong>@else <strong style="color:#168257">Released / no QC hold</strong>@endif</td></tr>
@empty<tr><td colspan="6">No receipt lots recorded.</td></tr>@endforelse
</tbody></table></div>{{ $layers->links() }}
</section>
@endsection
