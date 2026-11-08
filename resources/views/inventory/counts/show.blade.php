@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Physical Count {{ $count->number }}</h1><p>{{ $count->counted_on?->format('Y-m-d') }} · {{ ucfirst($count->status) }} · {{ $count->creator?->name }}</p></div><a class="btn btn-light" href="{{ route('inventory.counts.index') }}">All counts</a></div>
@if(session('success'))<div class="panel" style="border-color:#c6e7d5;color:#206a48">{{ session('success') }}</div>@endif
@if($errors->any())<div class="panel" style="border-color:#f4c5c5;color:#b12d2d">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="panel" style="padding:20px;border-radius:12px">
<h2>Review counted quantities and discrepancies</h2>
<p><strong>Count location:</strong> {{ $count->count_scope==='warehouse' ? ($count->warehouse?->code.' — '.$count->warehouse?->name) : ($count->count_scope==='unassigned'?'Unassigned stock pool':'Legacy company-wide snapshot') }}</p>
<p class="muted">Count reference: {{ $count->notes ?: 'Not specified' }}. If the source stock balance changed since this count was created, approval is blocked and a new count must be recorded.</p>
<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Material</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Posted adjustment</th></tr></thead><tbody>
@foreach($count->lines as $line)
@php($delta = \Brick\Math\BigDecimal::of((string)$line->counted_quantity)->minus((string)$line->expected_quantity))
<tr><td>{{ $line->material?->sku }}</td><td>{{ $line->material?->name }} ({{ $line->material?->unit }})</td><td>{{ $line->expected_quantity }}</td><td>{{ $line->counted_quantity }}</td>
<td style="font-weight:800;color:{{ $delta->isLessThan(0)?'#b6463d':($delta->isGreaterThan(0)?'#147b54':'#4d6d8b') }}">{{ $delta->isGreaterThan(0)?'+':'' }}{{ $delta->toScale(6) }}</td>
<td>{{ $line->adjustment?->id ? 'ADJ-'.$line->adjustment->id : ($delta->isZero()?'No difference':'Not posted') }}</td></tr>
@endforeach
</tbody></table></div>
@if($count->status === 'draft')
@can('inventory.update')
<div style="border-top:1px solid #e4eaf4;margin-top:16px;padding-top:16px"><p class="muted">Approval posts location-specific differences atomically and refuses stale snapshots. Reductions cannot exceed available stock or breach production reservations. Stock postings cannot be edited afterward.</p>
<form action="{{ route('inventory.counts.approve',$count) }}" method="POST">@csrf
<button type="submit" class="btn" onclick="return confirm('Approve this count and post every discrepancy to the inventory ledger?')">Approve and post stock differences</button></form></div>
@endcan
@else
<p style="margin-top:15px;color:#256947">Approved by {{ $count->approver?->name ?? 'Unknown' }} at {{ $count->approved_at?->format('Y-m-d H:i') }}</p>
@endif
</section>
@endsection
