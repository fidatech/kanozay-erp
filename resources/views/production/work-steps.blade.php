@extends('layouts.kanozay')
@section('content')
<style>
.ws-panel{padding:21px;background:#fff;border:1px solid #dce7f1;border-radius:12px;margin:14px 0}
.ws-wrap{overflow:auto}.ws-panel table{min-width:960px;font-size:12px;width:100%}
.ws-panel th,.ws-panel td{padding:12px;vertical-align:top}
.ws-panel th{background:#edf5fc}
.ws-warning{padding:12px;border-radius:8px;background:#fff8e7;color:#71551a;font-size:12px;line-height:1.7}
.ws-status{border-radius:8px;padding:4px 8px;background:#eaf2fc;white-space:nowrap}
.ws-form{display:grid;gap:8px;min-width:230px}
.ws-form textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d9e3ed;border-radius:8px}
</style>
<div class="heading"><div><h1>Production Work Orders</h1><p>Approved routing snapshots, actual machine work, completion evidence and stage sequencing</p></div>
<a href="{{ route('production.orders.index') }}" class="btn btn-light">Production orders</a></div>
@if(session('success'))<p class="notice">{{ session('success') }}</p>@endif
@if($errors->any())<p class="notice notice-warning">{{ implode(' ', $errors->all()) }}</p>@endif
<div class="ws-warning">Approved routing instructions are captured when the production order is created and cannot be rewritten mid-batch. An order with routed stages must complete every stage after materials have been issued and before the production batch is submitted to QC. Orders without approved routing preserve the existing manufacturing workflow; adding routing later does not retroactively alter their plans.</div>
<section class="ws-panel"><h2>Production orders with routed work steps</h2>
<div class="ws-wrap"><table><thead><tr><th>Order</th><th>Recipe</th><th>State</th><th>Routed stages</th><th>Progress</th></tr></thead><tbody>
@forelse($orders as $order)
<tr><td><a href="{{ route('production.orders.show',$order) }}">{{ $order->number }}</a></td><td>{{ $order->recipe?->name }}</td><td>{{ ucwords(str_replace('_',' ',$order->status)) }}</td>
<td>{{ $byOrder[$order->id]->total_count??0 }}</td>
<td>{{ $byOrder[$order->id]->complete_count??0 }} completed</td></tr>
@empty<tr><td colspan="5">No production orders available.</td></tr>@endforelse
</tbody></table></div></section>
<section class="ws-panel"><h2>Routed operations and actual completion</h2>
<div class="ws-wrap"><table><thead><tr><th>Order</th><th>Step</th><th>Operation</th><th>Machine</th><th>Planned time</th><th>Status</th><th>Instructions</th><th>Actual work</th></tr></thead><tbody>
@forelse($steps as $step)<tr>
<td><a href="{{ route('production.orders.show',$step->production_order_id) }}">{{ $step->order_number }}</a><br><small>{{ ucfirst($step->order_status) }}</small></td>
<td>{{ $step->step }}</td><td>{{ $step->operation }}</td>
<td>{{ $step->equipment_code??'Not allocated' }}</td><td>{{ $step->estimated_minutes??'—' }} min</td>
<td><span class="ws-status">{{ ucfirst(str_replace('_',' ',$step->status)) }}</span></td>
<td>{{ $step->instructions }}</td>
<td>
@if($step->started_at)<small>Started {{ $step->started_at }}</small><br>@endif
@if($step->completed_at)<small>Completed {{ $step->completed_at }}</small><p>{{ $step->completion_notes }}</p>@endif
@if($step->order_status==='issued')
@canany(['manufacturing.update','administration.update'])
@if($step->status==='pending')
<form method="POST" action="{{ route('manufacturing.work-steps.advance',$step->id) }}">@csrf
<input type="hidden" name="action" value="start"><button class="btn btn-secondary">Start step</button></form>
@elseif($step->status==='in_progress')
<form method="POST" action="{{ route('manufacturing.work-steps.advance',$step->id) }}" class="ws-form">@csrf
<input type="hidden" name="action" value="complete">
<textarea name="notes" minlength="5" maxlength="3000" placeholder="Measurements, QC or completion reference" required></textarea>
<button class="btn">Complete step</button></form>
@endif
@endcanany
@endif
</td>
</tr>
@empty<tr><td colspan="8">No approved routing was captured for these orders.</td></tr>@endforelse
</tbody></table></div>{{ $steps->links() }}</section>
@endsection
