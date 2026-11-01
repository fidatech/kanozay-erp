@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px">
 <a href="{{ route('production.orders.index') }}">Back to production orders</a>
 <h2>Production plan {{ $order->number }}</h2>
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 <p>Recipe: <a href="{{ route('production.recipes.show', $order->recipe) }}">{{ $order->recipe->name }}</a> · {{ $order->package_count }} packages · {{ $order->package_count * $order->recipe->package_weight_kg }} kg planned output</p>
 <p><strong>Material requirements (saved when the plan was created)</strong></p>
 <div class="table-wrap"><table><thead><tr><th>Material</th><th>Required</th><th>Unit</th><th>Packaging</th><th>Stock item</th><th>On hand</th><th>Availability</th></tr></thead><tbody>
 @forelse($materials as $material)
 <tr>
  <td>{{ $material['name'] }}</td><td>{{ $material['quantity'] }}</td><td>{{ $material['unit'] }}</td><td>{{ $material['packaging'] ? 'Yes' : 'No' }}</td>
  <td>
   @if($material['sku'])<strong>{{ $material['sku'] }}</strong>@else<span class="muted">Not mapped</span>@endif
   @can('inventory.update')
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
   @endcan
  </td>
  <td>{{ $material['on_hand'] ?? '—' }}</td>
  <td>
   @if($material['on_hand'] === null)<span class="muted">Not mapped</span>
   @elseif($material['shortage'] !== '0.000000')<strong style="color:#b42318">Short by {{ $material['shortage'] }}</strong>
   @else<span style="color:#08734b">Enough stock</span>@endif
  </td>
 </tr>
 @empty<tr><td colspan="7">No saved material requirements are available for this older plan. Do not use its recipe for purchasing or production without reviewing and recreating the plan.</td></tr>@endforelse
 </tbody></table></div>
 @can('inventory.view')<p><a href="{{ route('inventory.materials.index') }}">Open raw-material inventory</a></p>@endcan
 <p class="muted">Availability uses current on-hand balances and does not account for other planned orders. Creating or mapping this plan does not reserve stock, start manufacturing, or post finance transactions.</p>
</div>
@endsection
