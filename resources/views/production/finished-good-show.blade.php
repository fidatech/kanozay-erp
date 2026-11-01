@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1>{{ $good->sku }} — {{ $good->name }}</h1><p>Finished goods stock ledger and batch release history</p></div>
 <div class="actions"><a class="btn" href="{{ route('production.finished-goods.index') }}">Back to finished goods</a></div>
</div>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:16px">
 <div class="card"><div class="muted">Packages on hand</div><div class="metric-value">{{ number_format($good->on_hand_packages) }}</div></div>
 <div class="card"><div class="muted">Package unit</div><div class="metric-value">{{ $good->package_unit }}</div></div>
 <div class="card"><div class="muted">Weight per package</div><div class="metric-value">{{ $good->package_weight_kg }} kg</div></div>
</div>
<div class="card" style="padding:20px">
 <h3>Stock receipts from approved batches</h3>
 <div class="table-wrap"><table><thead><tr><th>Date</th><th>Batch</th><th>Production order</th><th>Movement</th><th>Packages received</th></tr></thead><tbody>
 @forelse($movements as $movement)
  <tr><td>{{ $movement->occurred_on->format('Y-m-d') }}</td>
   <td>{{ $movement->batch?->batch_number ?? '—' }}</td>
   <td>@if($movement->batch?->order)<a href="{{ route('production.orders.show', $movement->batch->order) }}">{{ $movement->batch->order->number }}</a>@else — @endif</td>
   <td>{{ str_replace('_', ' ', ucfirst($movement->type)) }}</td>
   <td>{{ number_format($movement->quantity_packages) }}</td></tr>
 @empty<tr><td colspan="5">No finished goods have been released for this product.</td></tr>@endforelse
 </tbody></table></div>
 {{ $movements->links() }}
 <p class="muted">Stock receipts are append-only and linked to a laboratory-approved production batch. Stock dispatches and valuation will be added in later phases.</p>
</div>
@endsection
