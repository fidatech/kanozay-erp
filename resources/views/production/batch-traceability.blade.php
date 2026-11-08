@extends('layouts.kanozay')
@section('content')
<style>
.trace-panel{background:#fff;border:1px solid #dce7ef;border-radius:12px;padding:20px;margin:14px 0}
.trace-table{overflow:auto}.trace-panel table{min-width:880px;font-size:12px}
.trace-panel th,.trace-panel td{padding:11px;vertical-align:top}
.trace-panel th{background:#f0f5fc}
.trace-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.trace-stat{border:1px solid #dce8f3;border-radius:9px;padding:14px}
.trace-stat strong{display:block;font-size:19px;margin-top:7px}
.trace-info{background:#fff8e8;border:1px solid #eed6aa;color:#765424;padding:12px;border-radius:8px;font-size:12px}
@media print{@page{size:A4;margin:12mm}.top,.rail,.sidebar,.heading .actions,nav,.no-print{display:none!important}.trace-panel{break-inside:avoid}}
</style>
<div class="heading">
 <div><h1>Production batch traceability</h1>
 <p>{{ $batch->batch_number }} · {{ $batch->finishedGood?->sku }} · {{ ucfirst(str_replace('_',' ',$batch->status)) }}</p></div>
 <div class="actions"><a class="btn btn-light" href="{{ route('production.orders.show',$batch->order) }}">Production order</a>
 <button class="btn no-print" type="button" onclick="window.print()">Print trace report</button></div>
</div>
<section class="trace-panel">
<h2>Batch identity and release</h2>
<div class="trace-grid">
 <div class="trace-stat">Original plan<strong>{{ $batch->order?->number }}</strong></div>
 <div class="trace-stat">Recipe<strong>{{ $batch->order?->planned_recipe_name }}</strong></div>
 <div class="trace-stat">Reported packages<strong>{{ $batch->reported_packages }}</strong></div>
 <div class="trace-stat">Accepted / rejected<strong>{{ $batch->accepted_packages }} / {{ $batch->rejected_packages }}</strong></div>
 <div class="trace-stat">QC decision<strong>{{ $batch->decided_at?->format('Y-m-d H:i')??'Pending' }}</strong></div>
 <div class="trace-stat">Warehouse release<strong>{{ $batch->released_at?->format('Y-m-d H:i')??'Not released' }}</strong></div>
</div>
<p style="margin-top:14px"><strong>Finished-good lot control:</strong> {{ $lotControl?->status??'Not registered' }}.
 @if($lotControl?->manufactured_on) Manufactured {{ $lotControl->manufactured_on }}. @endif
 @if($lotControl?->expires_on) Expires {{ $lotControl->expires_on }}. @endif
</p>
<p class="trace-info">This view follows posted material issues to their original FIFO layers. Warehouse transfers preserve source-lot identity. Historical material issues with no cost-layer reference are shown explicitly, without fabricated supplier or valuation data.</p>
</section>
<section class="trace-panel">
<h2>Raw materials and packaging consumed</h2>
<div class="trace-table"><table><thead><tr>
<th>Material</th><th>Type</th><th>Quantity / unit</th><th>Issue warehouse</th><th>Source FIFO lot</th><th>Supplier / GRN</th><th>Cost</th><th>Issue date</th>
</tr></thead><tbody>
@forelse($issues as $issue)
<tr>
<td>{{ $issue->material_name }}</td>
<td>{{ $issue->is_packaging?'Packaging':'Ingredient' }}</td>
<td>{{ $issue->quantity }} {{ $issue->unit }}</td>
<td>{{ $issue->warehouse_code??'Unassigned pool' }}</td>
<td>
@if($issue->inventory_cost_layer_id)
<a href="{{ route('manufacturing.traceability.source-lot',$issue->inventory_cost_layer_id) }}">Lot #{{ $issue->inventory_cost_layer_id }}</a>
<div>{{ str_replace('_',' ',$issue->source_type??'') }} #{{ $issue->source_id }}</div>
@else <span style="color:#9a5b13">Legacy / unvalued lot unknown</span>@endif
</td>
<td>{{ $issue->supplier_name??'Unknown / not purchased' }}
<div>{{ $issue->receipt_number??'No GRN' }} @if($issue->purchase_number) · {{ $issue->purchase_number }} @endif</div></td>
<td>{{ $issue->is_valued && $issue->amount!==null ? ($issue->currency_code.' '.$issue->amount):'Unvalued' }}</td>
<td>{{ $issue->occurred_on }}</td>
</tr>
@empty<tr><td colspan="8">No source consumption rows are available for this batch. Investigate material issue history before certifying traceability.</td></tr>
@endforelse
</tbody></table></div>{{ $issues->links() }}
</section>
<section class="trace-panel">
<h2>Manufacturing stages</h2>
<div class="trace-table"><table><thead><tr><th>Stage</th><th>Operation</th><th>Status</th><th>Started</th><th>Completed</th><th>Completion notes</th></tr></thead><tbody>
@forelse($workSteps as $step)
<tr><td>{{ $step->step }}</td><td>{{ $step->operation }}</td><td>{{ $step->status }}</td><td>{{ $step->started_at??'—' }}</td><td>{{ $step->completed_at??'—' }}</td><td>{{ $step->completion_notes??'—' }}</td></tr>
@empty<tr><td colspan="6">No approved routing steps were frozen for this order.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="trace-panel">
<h2>Frozen material costs</h2>
<div class="trace-table"><table><thead><tr><th>Currency</th><th>Material value</th><th>Valued quantity</th><th>Unvalued quantity</th></tr></thead><tbody>
@forelse($batch->costs as $cost)
<tr><td>{{ $cost->currency_code??'Unvalued' }}</td><td>{{ $cost->material_cost??'—' }}</td><td>{{ $cost->consumed_quantity }}</td><td>{{ $cost->unvalued_quantity }}</td></tr>
@empty<tr><td colspan="4">No frozen material cost snapshot yet. Costs are captured at release, not at planning.</td></tr>@endforelse
</tbody></table></div>
<p class="muted">Material source currencies are kept separate. Additional approved production overhead is managed in the costing workspace; this report does not create a valuation or capitalization journal.</p>
</section>
<section class="trace-panel">
<h2>Outbound stock and customer sales</h2>
<div class="trace-grid">
<div><h3>Dispatch records</h3><div class="trace-table"><table>
<thead><tr><th>Dispatch</th><th>Date</th><th>Packages</th></tr></thead><tbody>
@forelse($dispatched as $row)
<tr><td>{{ $row->number }}</td><td>{{ $row->dispatch_date }}</td><td>{{ $row->quantity_packages }}</td></tr>
@empty<tr><td colspan="3">No dispatches recorded.</td></tr>@endforelse
</tbody></table></div></div>
<div><h3>Sales references</h3><div class="trace-table"><table>
<thead><tr><th>Invoice</th><th>Date</th><th>Packages</th></tr></thead><tbody>
@forelse($sales as $row)
<tr><td>{{ $row->number }}</td><td>{{ $row->invoice_date }}</td><td>{{ $row->quantity_packages }}</td></tr>
@empty<tr><td colspan="3">No sales invoice lines recorded for this batch.</td></tr>@endforelse
</tbody></table></div></div>
</div>
<p class="muted">The outbound sections display the most recent 50 records of each type. Full customer recall communication remains controlled through the finished-good recall workflow.</p>
</section>
@endsection
