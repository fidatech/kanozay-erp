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

<div class="panel" style="margin-top:20px;padding:20px">
 <h3>Production batch and laboratory release</h3>
 @if($order->status === 'issued')
  <p>Raw materials have been issued. Submit the actual packaged output for laboratory inspection before finished goods can enter stock.</p>
  @can('manufacturing.update')
  <form method="POST" action="{{ route('production.orders.batches.store', $order) }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
   @csrf
   <label>Finished product
    <select name="finished_good_id" required>
     <option value="">Choose product</option>
     @foreach($finishedGoods as $good)
      @if($good->package_unit === $order->recipe->package_unit && \Brick\Math\BigDecimal::of((string) $good->package_weight_kg)->compareTo(\Brick\Math\BigDecimal::of((string) $order->recipe->package_weight_kg)) === 0 && (blank($order->recipe->product_code) || $good->sku === $order->recipe->product_code))
      <option value="{{ $good->id }}">{{ $good->sku }} — {{ $good->name }}</option>
      @endif
     @endforeach
    </select>
   </label>
   <label>Packages produced<input type="number" name="reported_packages" min="1" max="{{ $order->package_count }}" value="{{ old('reported_packages', $order->package_count) }}" required></label>
   <label>Production notes<input name="notes" maxlength="2000" value="{{ old('notes') }}"></label>
   <button type="submit" class="btn">Submit batch for laboratory</button>
  </form>
  @endcan
  @can('inventory.view')<p><a href="{{ route('production.finished-goods.index') }}">Manage finished goods catalog</a></p>@endcan
 @elseif($order->batch)
  @php($batch = $order->batch)
  <p><strong>Batch:</strong> {{ $batch->batch_number }} · <strong>Product:</strong> {{ $batch->finishedGood?->sku }} · <strong>Status:</strong> {{ str_replace('_', ' ', ucfirst($batch->status)) }}</p>
  <div class="table-wrap"><table><thead><tr><th>Produced</th><th>Accepted</th><th>Rejected</th><th>Lab decision</th><th>Stock release</th></tr></thead><tbody>
   <tr><td>{{ $batch->reported_packages }}</td><td>{{ $batch->accepted_packages }}</td><td>{{ $batch->rejected_packages }}</td>
    <td>{{ $batch->decided_at?->format('Y-m-d H:i') ?? 'Pending' }}</td><td>{{ $batch->released_at?->format('Y-m-d H:i') ?? 'Not released' }}</td></tr>
  </tbody></table></div>
  @if($batch->notes)<p><strong>Production notes:</strong> {{ $batch->notes }}</p>@endif
  @if($batch->qc_notes)<p><strong>Laboratory notes:</strong> {{ $batch->qc_notes }}</p>@endif
  <h4>Laboratory test results</h4>
  <div class="table-wrap"><table><thead><tr><th>Test</th><th>Specification</th><th>Observation</th><th>Result</th><th>Recorded</th></tr></thead><tbody>
   @forelse($batch->checks as $check)
   <tr><td>{{ $check->test_name }}</td><td>{{ $check->specification ?? '—' }}</td><td>{{ $check->observation ?? '—' }}</td>
    <td>{{ strtoupper($check->result) }}</td><td>{{ $check->created_at->format('Y-m-d H:i') }}</td></tr>
   @empty<tr><td colspan="5">No laboratory tests have been recorded.</td></tr>@endforelse
  </tbody></table></div>
  @if($batch->status === 'pending_qc')
   @can('laboratory.create')
   <h4>Record laboratory test</h4>
   <form method="POST" action="{{ route('production.orders.batches.checks.store', [$order, $batch]) }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end">
    @csrf
    <label>Test name<input name="test_name" maxlength="120" required></label>
    <label>Specification<input name="specification" maxlength="2000"></label>
    <label>Observed result<input name="observation" maxlength="2000"></label>
    <label>Assessment<select name="result" required><option value="pass">Pass</option><option value="fail">Fail</option></select></label>
    <button type="submit" class="btn">Record test</button>
   </form>
   @endcan
   @can('laboratory.update')
   <h4>Laboratory decision</h4>
   <form method="POST" action="{{ route('production.orders.batches.decide', [$order, $batch]) }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end">
    @csrf
    <label>Decision<select name="decision" required><option value="approve">Approve tested batch</option><option value="reject">Reject batch</option></select></label>
    <label>Rejected packages (for approval)<input type="number" name="rejected_packages" min="0" max="{{ $batch->reported_packages }}" value="0" required></label>
    <label>Decision notes<input name="qc_notes" maxlength="2000" required></label>
    <button type="submit" class="btn">Save QC decision</button>
   </form>
   <p class="muted">At least one test is required. All tests must pass for approval; any failed test requires rejection. Rejected packages never enter inventory.</p>
   @endcan
  @elseif($batch->status === 'qc_approved')
   @can('inventory.update')
   <h4>Release accepted finished goods</h4>
   <form method="POST" action="{{ route('production.orders.batches.release', [$order, $batch]) }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    @csrf
    <label>Release date<input type="date" name="occurred_on" value="{{ now()->toDateString() }}" min="{{ $batch->decided_at?->toDateString() }}" max="{{ now()->toDateString() }}" required></label>
    <button type="submit" class="btn">Release {{ $batch->accepted_packages }} accepted packages to stock</button>
   </form>
   @endcan
  @elseif($batch->status === 'qc_rejected')
   <p style="color:#b42318">Batch rejected by laboratory. No finished goods have been released.</p>
  @else
   <p style="color:#08734b">Finished goods released. This batch cannot be released again.</p>
  @endif
 @endif
</div>

 @can('inventory.view')<p><a href="{{ route('inventory.materials.index') }}">Open raw-material inventory</a></p>@endcan
 <p class="muted">Availability combines demand across lines mapped to the same stock item and excludes stock reserved by other production orders. Reserving does not consume stock. Posting an issue records actual consumption. Finished goods are added only after laboratory approval and a separate warehouse release. Inventory valuation and financial journals are not yet posted.</p>
</div>
@endsection
