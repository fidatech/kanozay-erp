@extends('layouts.kanozay')
@section('content')
<style>
.source-panel{padding:20px;background:#fff;border:1px solid #dce7f0;border-radius:12px;margin:15px 0}
.source-panel table{min-width:850px;font-size:12px}
.source-panel th,.source-panel td{padding:11px}
.source-note{padding:12px;border-radius:8px;background:#fff8e6;color:#755428;font-size:12px}
</style>
<div class="heading">
 <div><h1>Raw-material source lot traceability</h1>
 <p>Lot #{{ $layer->id }} · {{ $layer->material?->sku }} · {{ $layer->material?->name }}</p></div>
 <a class="btn btn-light" href="{{ route('inventory.warehouse-valuation.index') }}">Warehouse FIFO valuation</a>
</div>
<section class="source-panel">
<h2>Original material provenance</h2>
<p><strong>Source:</strong> {{ str_replace('_',' ',$layer->source_type) }} #{{ $layer->source_id }}
 · <strong>Received:</strong> {{ $layer->received_on?->format('Y-m-d') }}
 · <strong>Initial quantity:</strong> {{ $layer->original_quantity }} {{ $layer->material?->unit }}
 · <strong>Remaining quantity:</strong> {{ $layer->remaining_quantity }} {{ $layer->material?->unit }}
 · <strong>Cost currency:</strong> {{ $layer->currency_code??'Unvalued' }}</p>
<h3>Current verified lot positions</h3>
<div class="table-wrap"><table><thead><tr><th>Location</th><th>Remaining quantity</th><th>Warehouse position</th></tr></thead><tbody>
@forelse($positions as $position)
<tr><td>{{ $position->warehouse_name??'Unassigned pool' }}</td><td>{{ $position->quantity }}</td><td>{{ $position->location_key }}</td></tr>
@empty<tr><td colspan="3">No reconciled location positions. Check historical inventory before moving or consuming this lot.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="source-panel">
<h2>Production orders and finished-good batches affected by this lot</h2>
<p class="source-note">Each row represents an actual FIFO consumption, not an estimated recipe allocation. A source material can be used in several production orders. Selecting a batch opens its upstream and downstream history. Where an order has not yet produced a batch, the production order remains visible.</p>
<div class="table-wrap"><table><thead><tr><th>Production order</th><th>Finished-good batch</th><th>Product</th><th>Material category</th><th>Quantity used</th><th>Issue date</th><th>Batch status</th><th>Lot control</th></tr></thead><tbody>
@forelse($batches as $row)
<tr>
<td><a href="{{ route('production.orders.show',$row->order_id) }}">{{ $row->order_number }}</a></td>
<td>@if($row->batch_id)<a href="{{ route('manufacturing.traceability.batch',$row->batch_id) }}">{{ $row->batch_number }}</a>
@else<span style="color:#9a5719">Not yet submitted</span>@endif</td>
<td>{{ $row->product_name??'Pending batch' }}</td>
<td>{{ $row->is_packaging?'Packaging':'Ingredient' }}</td>
<td>{{ $row->quantity }} {{ $layer->material?->unit }}</td>
<td>{{ $row->occurred_on }}</td>
<td>{{ $row->batch_status??'Pre-batch' }}</td>
<td>{{ $row->lot_control_status??'Not registered' }}</td>
</tr>
@empty<tr><td colspan="8">No recorded production issue has consumed this lot.</td></tr>@endforelse
</tbody></table></div>
{{ $batches->links() }}
</section>
@endsection
