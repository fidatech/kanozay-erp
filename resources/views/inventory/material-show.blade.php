@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-box"></i> {{ $material->name }}</h1>
 <p><a href="{{ route('inventory.materials.index') }}">Raw materials</a> / {{ $material->sku }}</p></div>
 @can('inventory.update') @if($material->is_active)<button type="button" class="btn" onclick="document.getElementById('stock-adjustment-modal').showModal()"><i class="fa-solid fa-scale-balanced"></i> Adjust stock</button>@endif @endcan
</div>
@if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
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
@can('inventory.update') @if($material->is_active)
<dialog class="k-modal" id="stock-adjustment-modal">
 <form class="modal-form" method="POST" action="{{ route('inventory.materials.adjustments.store',$material) }}">@csrf
  <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
  <div class="modal-head"><h2>Adjust {{ $material->name }} stock</h2><button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button></div>
  <div class="modal-body">
   <p>Current balance: <strong>{{ $material->on_hand_quantity }} {{ $material->unit }}</strong>. Enter the physical count difference. Decreases cannot use stock reserved for production.</p>
   <label>Adjustment type<select name="direction" required><option value="adjustment_in" @selected(old('direction')==='adjustment_in')>Increase stock</option><option value="adjustment_out" @selected(old('direction')==='adjustment_out')>Decrease stock</option></select></label>
   <label>Quantity <input name="quantity" type="text" inputmode="decimal" value="{{ old('quantity') }}" required></label>
   <label>Adjustment date <input name="occurred_on" type="date" value="{{ old('occurred_on', now()->toDateString()) }}" required></label>
   <label>Reason <textarea name="reason" minlength="5" maxlength="1000" rows="3" required>{{ old('reason') }}</textarea></label>
  </div>
  <div class="modal-foot"><button type="button" class="btn btn-light" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn">Post adjustment</button></div>
 </form>
</dialog>
@if($errors->any())<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('stock-adjustment-modal')?.showModal());</script>@endif
@endif @endcan
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
