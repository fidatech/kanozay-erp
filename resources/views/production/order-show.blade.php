@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px">
 <a href="{{ route('production.orders.index') }}">Back to production orders</a>
 <h2>Production plan {{ $order->number }}</h2>
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 <p>Recipe: <a href="{{ route('production.recipes.show', $order->recipe) }}">{{ $order->recipe->name }}</a> · {{ $order->package_count }} packages · {{ $order->package_count * $order->recipe->package_weight_kg }} kg planned output</p>
 <p><strong>Status:</strong> {{ ucfirst($order->status) }}</p>
 @can('administration.update')
 @can('inventory.update')
 @if($order->status === 'planned')
 <form method="POST" action="{{ route('production.orders.reserve', $order) }}" style="display:inline-block;margin:8px 8px 16px 0">
  @csrf <button type="submit" class="btn">Reserve all materials</button>
 </form>
 @endif
 @if(in_array($order->status, ['planned', 'reserved'], true))
 <form method="POST" action="{{ route('production.orders.cancel', $order) }}" style="display:inline-block;margin:8px 0 16px">
  @csrf <button type="submit" class="btn">Cancel plan</button>
 </form>
 @endif
 @endcan
 @endcan
 <p><strong>Material requirements (saved when the plan was created)</strong></p>
 <div class="table-wrap"><table><thead><tr><th>Material</th><th>Required</th><th>Unit</th><th>Packaging</th><th>Stock item</th><th>On hand</th><th>Available</th><th>Reserved</th><th>Issued</th><th>Availability</th></tr></thead><tbody>
 @forelse($materials as $material)
 <tr>
  <td>{{ $material['name'] }}</td><td>{{ $material['quantity'] }}</td><td>{{ $material['unit'] }}</td><td>{{ $material['packaging'] ? 'Yes' : 'No' }}</td>
  <td>
   @if($material['sku'])<strong>{{ $material['sku'] }}</strong>@else<span class="muted">Not mapped</span>@endif
   @can('inventory.update')
   @if($order->status === 'planned')
   <form method="POST" action="{{ route('production.orders.materials.link', [$order, $material['id']]) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px">
    @csrf @method('PATCH')
    <select name="inventory_material_id" required aria-label="Stock item for {{ $material['name'] }}" style="min-width:160px">
     <option value="">Choose stock item</option>
     @foreach($catalogMaterials as $stockItem)
      @if($stockItem->unit === $material['unit'] && $stockItem->is_packaging === $material['packaging'])
      <option value="{{ $stockItem->id }}" @selected($material['catalog_id'] === $stockItem->id)>{{ $stockItem->sku }} — {{ $stockItem->name }}</option>
      @endif
     @endforeach
    </select><button type="submit" class="btn">Link</button>
   </form>
   @endif
   @endcan
  </td>
  <td>{{ $material['on_hand'] ?? '—' }}</td><td>{{ $material['available'] ?? '—' }}</td><td>{{ $material['reserved'] }}</td><td>{{ $material['issued'] }}</td>
  <td>
   @if($material['on_hand'] === null)<span class="muted">Not mapped</span>
   @elseif($material['combined_line'])<span class="muted">Combined with first row for this SKU</span>
   @elseif($material['shortage'] !== '0.000000')<strong style="color:#b42318">Short by {{ $material['shortage'] }} (combined demand)</strong>
   @else<span style="color:#08734b">Enough stock</span>@endif
  </td>
 </tr>
 @empty<tr><td colspan="10">No saved material requirements are available for this older plan. Do not use its recipe for purchasing or production without reviewing and recreating the plan.</td></tr>@endforelse
 </tbody></table></div>
 @can('administration.update')
 @can('inventory.update')
 @if($order->status === 'reserved')
 <h3>Issue actual materials</h3>
 <p>Enter quantities actually used. Unused reservations will be released when the issue is posted. A completed issue cannot be submitted twice.</p>
 <form method="POST" action="{{ route('production.orders.issue', $order) }}">
  @csrf
  <label for="production-issue-date">Issue date</label>
  <input id="production-issue-date" type="date" name="occurred_on" value="{{ old('occurred_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
  <div class="table-wrap"><table><thead><tr><th>Material</th><th>Planned quantity</th><th>Actual quantity</th><th>Unit</th></tr></thead><tbody>
   @foreach($materials as $material)
   <tr><td>{{ $material['name'] }}</td><td>{{ $material['quantity'] }}</td>
   <td><input type="text" inputmode="decimal" name="actual_quantities[{{ $material['id'] }}]" value="{{ old('actual_quantities.'.$material['id'], $material['quantity']) }}" required aria-label="Actual quantity for {{ $material['name'] }}"></td>
   <td>{{ $material['unit'] }}</td></tr>
   @endforeach
  </tbody></table></div>
  <button type="submit" class="btn">Post material issue</button>
 </form>
 @endif
 @endcan
 @endcan
 @can('inventory.view')<p><a href="{{ route('inventory.materials.index') }}">Open raw-material inventory</a></p>@endcan
 <p class="muted">Availability combines demand across lines mapped to the same stock item and excludes stock reserved by other production orders. Reserving does not consume stock. Posting an issue records actual consumption, but does not create finished goods or financial journals.</p>
</div>
@endsection
