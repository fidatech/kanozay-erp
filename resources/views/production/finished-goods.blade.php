@extends('layouts.kanozay')
@section('content')
<div style="display:flex;gap:9px;flex-wrap:wrap;margin:10px 0">
<a class="btn btn-light" href="{{ route('inventory.finished-good-warehouses.index') }}">Finished-goods warehouse allocations</a>
<a class="btn btn-light" href="{{ route('inventory.finished-good-lots.index') }}">Batch expiry & recalls</a></div>
<div class="heading">
 <div><h1>Finished goods inventory</h1><p>Approved production batches are released here only after laboratory acceptance.</p></div>
 <div class="actions"><a class="btn" href="{{ route('production.orders.index') }}">Production orders</a></div>
</div>
@if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
@can('inventory.create')
<div class="card" style="padding:20px;margin-bottom:16px">
 <h3>Add finished good</h3>
 <p class="muted">The SKU, package unit and weight must match the production recipe when linking a batch.</p>
 <form method="POST" action="{{ route('production.finished-goods.store') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
  @csrf
  <label>SKU<input name="sku" value="{{ old('sku') }}" maxlength="64" required></label>
  <label>Product name<input name="name" value="{{ old('name') }}" maxlength="180" required></label>
  <label>Package unit<input name="package_unit" value="{{ old('package_unit', 'bag') }}" maxlength="40" required></label>
  <label>Catalog unit<select name="catalog_unit_id"><option value="">No approved unit link</option>
@foreach($catalogUnits as $unit)<option value="{{ $unit->id }}">{{ $unit->data['symbol']??'' }} · {{ $unit->data['name']??'' }}</option>@endforeach
</select></label>
<label>Product category<select name="catalog_category_id"><option value="">Unclassified</option>
@foreach($catalogCategories as $category)@if(in_array($category->data['group']??null,['finished','other'],true))<option value="{{ $category->id }}">{{ $category->data['name']??'' }}</option>@endif @endforeach</select></label>
<label>Package weight (kg)<input name="package_weight_kg" type="number" step="0.001" min="0.001" value="{{ old('package_weight_kg') }}" required></label>
  <button class="btn" type="submit">Add product</button>
 </form>
</div>
@endcan
<div class="card" style="padding:20px">
 <h3>Available finished goods</h3>
 <div class="table-wrap"><table>
  <thead><tr><th>SKU</th><th>Product</th><th>Unit</th><th>Catalog category</th><th>Weight (kg)</th><th>Packages on hand</th><th>Status</th></tr></thead>
  <tbody>
  @forelse($goods as $good)
  <tr><td><a href="{{ route('production.finished-goods.show', $good) }}"><strong>{{ $good->sku }}</strong></a></td><td>{{ $good->name }}</td><td>{{ $good->package_unit }}</td>
   <td>{{ $good->catalogCategory?->data['name']??'Unclassified' }}
@can('inventory.update')
<details><summary>Assign catalog</summary><form method="POST" action="{{ route('production.finished-goods.catalog',$good) }}">@csrf
<select name="catalog_unit_id"><option value="">No unit link</option>
@foreach($catalogUnits as $unit)@if(($unit->data['symbol']??null)===$good->package_unit)<option value="{{ $unit->id }}" @selected($good->catalog_unit_id===$unit->id)>{{ $unit->data['name']??'' }}</option>@endif @endforeach</select>
<select name="catalog_category_id"><option value="">Unclassified</option>
@foreach($catalogCategories as $category)@if(in_array($category->data['group']??null,['finished','other'],true))<option value="{{ $category->id }}" @selected($good->catalog_category_id===$category->id)>{{ $category->data['name']??'' }}</option>@endif @endforeach</select><button class="btn btn-light">Assign</button>
</form></details>@endcan
</td><td>{{ $good->package_weight_kg }}</td><td>{{ number_format($good->on_hand_packages) }}</td>
   <td>{{ $good->is_active ? 'Active' : 'Inactive' }}</td></tr>
  @empty<tr><td colspan="7">No finished goods are registered yet.</td></tr>@endforelse
  </tbody>
 </table></div>
 {{ $goods->links() }}
</div>
@endsection
