@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-box"></i> {{ $material->name }}</h1>
 <p><a href="{{ route('inventory.materials.index') }}">Raw materials</a> / {{ $material->sku }}</p></div>
</div>
<div class="card" style="padding:22px;margin-bottom:18px">
 <h2>Stock balances</h2>
 <p><strong>SKU:</strong> {{ $material->sku }} · <strong>Unit:</strong> {{ $material->unit }} · <strong>Type:</strong> {{ $material->is_packaging ? 'Packaging' : 'Ingredient' }}</p>
 <div style="display:flex;gap:28px;flex-wrap:wrap">
  <p><strong>On hand</strong><br>{{ $material->on_hand_quantity }}</p>
  <p><strong>Reserved for production</strong><br>{{ $material->reserved_quantity }}</p>
  <p><strong>Available</strong><br>{{ $material->available_quantity }}</p>
 </div>
 <p class="muted">Reservations do not create stock movements. Actual production issues and confirmed goods receipts appear below.</p>
</div>
<div class="card" style="padding:22px">
 <h2>Stock movement history</h2>
 <div class="table-wrap"><table>
  <thead><tr><th>Date</th><th>Movement</th><th>Direction</th><th>Quantity</th><th>Source</th><th>Notes</th></tr></thead>
  <tbody>
  @forelse($movements as $movement)
   <tr>
    <td>{{ $movement->occurred_on?->format('Y-m-d') }}</td>
    <td>{{ ucwords(str_replace('_', ' ', $movement->type)) }}</td>
    <td><strong style="color:{{ $movement->direction === 'in' ? '#08734b' : '#b42318' }}">{{ strtoupper($movement->direction) }}</strong></td>
    <td>{{ $movement->quantity }} {{ $material->unit }}</td>
    <td>{{ ucfirst(str_replace('_', ' ', $movement->reference_type)) }} #{{ $movement->reference_id }}</td>
    <td>{{ $movement->notes ?? '—' }}</td>
   </tr>
  @empty
   <tr><td colspan="6" class="empty">No stock movements recorded for this material.</td></tr>
  @endforelse
  </tbody>
 </table></div>
 {{ $movements->links() }}
</div>
@endsection
