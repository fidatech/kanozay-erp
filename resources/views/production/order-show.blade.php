@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px">
 <a href="{{ route('production.orders.index') }}">Back to production orders</a>
 <h2>Production plan {{ $order->number }}</h2>
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 @php($plannedOutput = $order->planned_package_weight_kg === null ? null : (string) \Brick\Math\BigDecimal::of((string) $order->planned_package_weight_kg)->multipliedBy((string) $order->package_count)->toScale(4))
 <p>Recipe: <a href="{{ route('production.recipes.show', $order->recipe) }}">{{ $order->planned_recipe_name }}</a> · {{ $order->package_count }} packages · {{ $plannedOutput ?? 'Not captured' }} kg planned output</p>
 @if($order->package_weight_kg_snapshot === null)
 <p class="muted" role="status">Legacy plan: recipe package specifications were not captured when this order was created. Confirm the original recipe before submitting a finished-goods batch.</p>
 @endif
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
 <div class="table-wrap"><table><thead><tr><th>Material</th><th>Required</th><th>Unit</th><th>Packaging</th><th>Stock item</th><th>On hand</th><th>Available</th><th>Reserved</th><th>Issued</th><th>Issue vs plan</th><th>Rejected / waste</th><th>Availability</th></tr></thead><tbody>
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
  @php($usageDiff=\Brick\Math\BigDecimal::of((string)$material['issued'])->minus((string)$material['quantity']))
  <td>
  @if(in_array($order->status,['issued','pending_qc','qc_approved','qc_rejected','completed'],true))
  <span style="color:{{ $usageDiff->isPositive()?'#9d5814':($usageDiff->isNegative()?'#2168a3':'#277748') }}">
   {{ $usageDiff->isPositive()?'+':'' }}{{ $usageDiff->toScale(6) }} {{ $material['unit'] }}
  </span>
  @else<span class="muted">Pending issue</span>@endif
  </td>
  <td>{{ $material['waste'] }}
  @can('manufacturing.update')
  @if(in_array($order->status, ['issued', 'pending_qc'], true) && (float) $material['issued'] > 0)
   <details><summary>Record waste</summary>
    <form method="POST" action="{{ route('production.orders.materials.waste', [$order, $material['id']]) }}" style="display:grid;gap:6px;min-width:150px;margin-top:6px">
     @csrf
     <input type="hidden" name="submission_token" value="{{ \Illuminate\Support\Str::uuid() }}">
     <label>Quantity<input name="quantity" inputmode="decimal" placeholder="0.000000" required></label>
     <label>Reason<input name="reason" maxlength="2000" minlength="5" required></label>
     <button class="btn" type="submit">Record</button>
    </form>
   </details>
  @endif
  @endcan
  </td>
  <td>
   @if($material['on_hand'] === null)<span class="muted">Not mapped</span>
   @elseif($material['combined_line'])<span class="muted">Combined with first row for this SKU</span>
   @elseif($material['shortage'] !== '0.000000')<strong style="color:#b42318">Short by {{ $material['shortage'] }} (combined demand)</strong>
   @else<span style="color:#08734b">Enough stock</span>@endif
  </td>
 </tr>
 @empty<tr><td colspan="12">No saved material requirements are available for this older plan. Do not use its recipe for purchasing or production without reviewing and recreating the plan.</td></tr>@endforelse
 </tbody></table></div>
 @if($order->materials->sum(fn ($line) => $line->wastes->count()) > 0)
 <h3>Recorded material waste and finance disposition</h3>
 <p class="muted">Waste has already been included in the original raw-material issue; reviewing it never reduces stock twice. A different authorized finance reviewer must classify each loss before QC warehouse release. Normal process loss remains included in approved output cost only with documented policy evidence. Abnormal loss and unresolved rework must not be capitalized to saleable finished goods until a separate verified disposition and GL correction workflow exists.</p>
 <p class="muted">Unreviewed: {{ $wasteReviewSummary['pending'] }} · Abnormal: {{ $wasteReviewSummary['abnormal'] }} · Rework pending: {{ $wasteReviewSummary['rework'] }}</p>
 <div class="table-wrap"><table><thead><tr><th>Material</th><th>Rejected quantity</th><th>Unit</th><th>Reason</th><th>Recorded at</th><th>Financial treatment</th></tr></thead><tbody>
 @foreach($order->materials as $line)
  @foreach($line->wastes as $waste)
  @php($financialReview=$wasteReviewSummary['reviews']->get($waste->id))
  <tr>
  <td>{{ $line->material_name }}</td><td>{{ $waste->quantity }}</td>
  <td>{{ $line->unit }}</td><td>{{ $waste->reason }}</td>
  <td>{{ $waste->created_at->format('Y-m-d H:i') }}</td>
  <td>
  @if($financialReview && $financialReview->treatment)
   <strong style="color:{{ $financialReview->treatment==='normal_process_loss'?'#08734b':'#aa4e26' }}">
    {{ match($financialReview->treatment){
        'normal_process_loss'=>'Normal process loss, policy reviewed',
        'abnormal_loss'=>'Abnormal loss: expense recognition required',
        'rework_required'=>'Unresolved rework: release blocked',
        default=>'Unknown financial treatment'
    } }}
   </strong>
   <div class="muted">Evidence: {{ $financialReview->approval_reference }}</div>
  @else
   <strong style="color:#9b5a20">Independent finance review required</strong>
   @if(auth()->user()->can('administration.update') || (auth()->user()->can('finance.update') && auth()->user()->can('manufacturing.view')))
   <details><summary>Record source-backed waste treatment</summary>
   <form method="POST" action="{{ route('production.orders.waste.review',[$order,$waste]) }}" style="display:grid;gap:7px;min-width:225px;margin-top:9px">
    @csrf
    <select name="treatment" required aria-label="Financial treatment">
     <option value="">Select physical and financial treatment</option>
     <option value="normal_process_loss">Normal loss, covered by signed manufacturing policy</option>
     <option value="abnormal_loss">Abnormal loss, requires separate expense posting</option>
     <option value="rework_required">Rework pending, output not yet saleable</option>
    </select>
    <input name="approval_reference" required minlength="4" maxlength="180" placeholder="Signed QC / finance review reference">
    <textarea name="review_notes" required minlength="20" maxlength="3000" rows="3" placeholder="State source evidence, approved normal-loss policy or abnormal/rework action"></textarea>
    <label style="font-size:12px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="confirm_no_second_stock_issue" value="1" required> Loss already issued; no second stock deduction</label>
    <button class="btn" type="submit" onclick="return confirm('Save irreversible independent finance waste classification? This action does not post a journal or deduct stock.')">Save independent review</button>
   </form></details>
   @endif
  @endif
  </td></tr>
  @endforeach
 @endforeach
 </tbody></table></div>
 @endif
 @can('administration.update')
 @can('inventory.update')
 @if($order->status === 'reserved')
 <h3>Issue actual materials</h3>
 <p>Enter quantities actually used. Unused reservations will be released when the issue is posted. A completed issue cannot be submitted twice.</p>
 <form method="POST" action="{{ route('production.orders.issue', $order) }}">
  @csrf
  <label for="production-issue-date">Issue date</label>
  <input id="production-issue-date" type="date" name="occurred_on" value="{{ old('occurred_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
  <div class="table-wrap"><table><thead><tr><th>Material</th><th>Planned quantity</th><th>Actual quantity</th><th>Warehouse source</th><th>Unit</th></tr></thead><tbody>
   @foreach($materials as $material)
   <tr><td>{{ $material['name'] }}</td><td>{{ $material['quantity'] }}</td>
   <td><input type="text" inputmode="decimal" name="actual_quantities[{{ $material['id'] }}]" value="{{ old('actual_quantities.'.$material['id'], $material['quantity']) }}" required aria-label="Actual quantity for {{ $material['name'] }}"></td>
   <td><select name="warehouse_ids[{{ $material['id'] }}]"><option value="">Unassigned stock</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} / {{ $warehouse->name }}</option>@endforeach</select></td><td>{{ $material['unit'] }}</td></tr>
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
      @if($good->package_unit === $order->planned_package_unit && \Brick\Math\BigDecimal::of((string) $good->package_weight_kg)->compareTo(\Brick\Math\BigDecimal::of((string) $order->planned_package_weight_kg)) === 0 && (blank($order->planned_product_code) || $good->sku === $order->planned_product_code))
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
  <p><a class="btn btn-light" href="{{ route('manufacturing.traceability.batch',$batch) }}">Batch source lots, packaging and delivery trace</a></p>
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
    <label>Receiving warehouse<select name="company_warehouse_id"><option value="">Unassigned finished goods pool</option>
    @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach
    </select></label>
    <button type="submit" class="btn">Release {{ $batch->accepted_packages }} accepted packages to stock</button>
   </form>
   @endcan
  @elseif($batch->status === 'qc_rejected')
   <p style="color:#b42318">Batch rejected by laboratory. No finished goods have been released.</p>
  @else
   <p style="color:#08734b">Finished goods released. This batch cannot be released again.</p>
   <h4>Raw-material cost snapshot</h4>
   @if($batch->costs->isNotEmpty())
    <div class="table-wrap"><table><thead><tr><th>Currency</th><th>Issued quantity valued</th><th>Ingredient cost</th><th>Packaging cost</th><th>Total material cost</th><th>Unvalued quantity</th></tr></thead><tbody>
     @foreach($batch->costs as $cost)
      <tr><td>{{ $cost->currency_code ?? 'Unvalued' }}</td><td>{{ $cost->consumed_quantity }}</td>
       <td>{{ $cost->ingredient_cost === null ? 'Legacy / unavailable' : $cost->ingredient_cost }}</td>
       <td>{{ $cost->packaging_cost === null ? 'Legacy / unavailable' : $cost->packaging_cost }}</td>
       <td>{{ $cost->material_cost === null ? '—' : $cost->currency_code.' '.rtrim(rtrim((string) $cost->material_cost, '0'), '.') }}</td>
       <td>{{ $cost->unvalued_quantity }}</td></tr>
     @endforeach
    </tbody></table></div>
   @else
    <p class="muted">No raw-material cost snapshot is available for this batch. Its issue records may predate valuation tracking or the order may not have had recorded material lines.</p>
   @endif
  @endif
 @endif
</div>

 @can('inventory.view')<p><a href="{{ route('inventory.materials.index') }}">Open raw-material inventory</a></p>@endcan
 <p class="muted">Batch submission requires enough issued packaging for the reported packages according to the approved recipe. Frozen cost snapshots keep ingredient and packaging costs separately in their source currencies. </p>
 <p class="muted">Availability combines demand across lines mapped to the same stock item and excludes stock reserved by other production orders. Reserving does not consume stock. Waste quantities classify materials already issued and do not deduct stock again. Finished goods are added only after laboratory approval and a separate warehouse release. Warehouse FIFO issues and material cost snapshots are recorded in their original currencies. Financial capitalization, COGS journals and reviewed overhead remain separate accounting workflows.</p>
</div>
@endsection
