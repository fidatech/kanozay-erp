@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-boxes-stacked"></i> Raw materials</h1>
 <p>Material catalog, on-hand stock, production reservations and available balances. Quantities use the recorded unit without automatic conversions.</p></div>
 <a class="btn btn-light" href="{{ route('inventory.valuation') }}">Inventory valuation</a>
 @can('inventory.create')
 <button type="button" class="btn" onclick="document.getElementById('create-material-modal').showModal()"><i class="fa-solid fa-plus"></i> Add material</button>
 @endcan
</div>
<div class="card" style="padding:22px">
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 <div class="table-wrap"><table><thead><tr><th>SKU</th><th>Material</th><th>Type</th><th>Unit</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Status</th><th>Opening stock</th></tr></thead><tbody>
 @forelse($materials as $material)
 <tr>
  <td><a href="{{ route('inventory.materials.show', $material) }}"><strong>{{ $material->sku }}</strong></a></td><td>{{ $material->name }}</td>
  <td>{{ $material->is_packaging ? 'Packaging' : 'Ingredient' }}</td><td>{{ $material->unit }}</td>
  <td>{{ $material->on_hand_quantity }}</td><td>{{ $material->reserved_quantity }}</td><td>{{ $material->available_quantity }}</td><td>{{ $material->is_active ? 'Active' : 'Inactive' }}</td>
  <td>
   @if($material->has_opening)Recorded
   @elseif(!$material->is_active)Inactive
   @else
    @can('inventory.update')
    <button type="button" class="btn" onclick="document.getElementById('opening-{{ $material->id }}').showModal()">Record opening</button>
    <dialog class="k-modal" id="opening-{{ $material->id }}">
     <form method="POST" action="{{ route('inventory.materials.opening', $material) }}" class="modal-form">@csrf
      <div class="modal-head"><h2>Opening stock: {{ $material->name }}</h2><button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button></div>
      <div class="modal-body">
       <p>Enter the verified initial quantity in <strong>{{ $material->unit }}</strong>. This ledger entry cannot be edited or deleted.</p>
       <label>Quantity <input name="quantity" type="number" step="0.000001" min="0.000001" required></label>
       <label>Unit cost (optional) <input name="unit_cost" type="number" step="0.000001" min="0.000001"></label>
       <label>Cost currency
        <select name="currency_id"><option value="">Unvalued opening stock</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select>
       </label>
       <label>Opening date <input name="occurred_on" type="date" value="{{ now()->toDateString() }}" required></label>
       <label>Notes <textarea name="notes" maxlength="2000" rows="3"></textarea></label>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="this.closest('dialog').close()">Cancel</button><button class="btn" type="submit">Post opening stock</button></div>
     </form>
    </dialog>
    @else Not recorded @endcan
   @endif
  </td>
 </tr>
 @empty<tr><td colspan="9" class="empty">No materials yet. Add a material to begin recording inventory.</td></tr>@endforelse
 </tbody></table></div>
 {{ $materials->links() }}
 <p class="muted" style="margin-top:18px">Reserving production materials reduces available stock without changing on-hand stock. Posting a production material issue reduces on-hand stock and releases its reservation. Confirmed purchase-order receipts increase stock through posted GRNs.</p>
</div>
@can('inventory.create')
<dialog class="k-modal" id="create-material-modal">
 <form class="modal-form" method="POST" action="{{ route('inventory.materials.store') }}">@csrf
  <div class="modal-head"><h2>Add material</h2><button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button></div>
  <div class="modal-body">
   <label>SKU <input name="sku" maxlength="64" required placeholder="RM-001"></label>
   <label>Name <input name="name" maxlength="180" required></label>
   <label>Stock unit <input name="unit" maxlength="30" required placeholder="kg, L, pcs"></label>
   <label>Material type <select name="is_packaging" required><option value="0">Ingredient / raw material</option><option value="1">Packaging</option></select></label>
   <p class="muted">Use the same unit as the approved recipe. Unit conversion is not yet supported.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn btn-light" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn">Save material</button></div>
 </form>
</dialog>
@endcan
@endsection
