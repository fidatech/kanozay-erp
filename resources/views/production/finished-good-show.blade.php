@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1>{{ $good->sku }} — {{ $good->name }}</h1><p>Finished-goods balance, released batches and dispatch history</p></div>
 <div class="actions"><a class="btn" href="{{ route('production.finished-goods.index') }}">Back to finished goods</a></div>
</div>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:16px">
 <div class="card"><div class="muted">Packages on hand</div><div class="metric-value">{{ number_format($good->on_hand_packages) }}</div></div>
 <div class="card"><div class="muted">Package unit</div><div class="metric-value">{{ $good->package_unit }}</div></div>
 <div class="card"><div class="muted">Weight per package</div><div class="metric-value">{{ $good->package_weight_kg }} kg</div></div>
</div>
<div class="card" style="padding:20px">
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 <h3>Available released batches</h3>
 @can('inventory.update')
  @forelse($availableBatches as $batch)
   <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;border-bottom:1px solid #e5ebf2;padding:10px 0">
    <span><strong>{{ $batch->batch_number }}</strong> · {{ $batch->available_packages }} packages remaining @if($batch->order) · {{ $batch->order->number }} @endif</span>
    <button type="button" class="btn" onclick="document.getElementById('dispatch-{{ $batch->id }}').showModal()">Dispatch</button>
   </div>
   <dialog class="k-modal" id="dispatch-{{ $batch->id }}">
    <form method="POST" action="{{ route('production.finished-goods.dispatches.store', $good) }}" class="modal-form" data-confirm-dispatch>
     @csrf <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
     <input type="hidden" name="production_batch_id" value="{{ $batch->id }}">
     <div class="modal-head"><h2>Dispatch {{ $good->name }}</h2><button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button></div>
     <div class="modal-body">
      <p>Batch {{ $batch->batch_number }} has {{ $batch->available_packages }} packages available.</p>
      <label>Packages <input name="quantity_packages" type="number" min="1" max="{{ $batch->available_packages }}" step="1" required></label>
      <label>Dispatch date <input name="dispatch_date" type="date" value="{{ now()->toDateString() }}" required></label>
      <label>Customer or dispatch reference <input name="reference" maxlength="100"></label>
      <label>Notes <textarea name="notes" maxlength="2000" rows="3"></textarea></label>
     </div>
     <div class="modal-foot"><button type="button" class="btn btn-light" onclick="this.closest('dialog').close()">Cancel</button><button class="btn" type="submit">Post dispatch</button></div>
    </form>
   </dialog>
  @empty<p class="muted">No released batch packages are available to dispatch.</p>@endforelse
 @else<p class="muted">You need inventory update permission to post a dispatch.</p>@endcan
 <h3 style="margin-top:24px">Stock receipts from approved batches</h3>
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
 <h3 style="margin-top:24px">Dispatch history</h3>
 <div class="table-wrap"><table><thead><tr><th>Date</th><th>Dispatch</th><th>Batch</th><th>Production order</th><th>Reference</th><th>Packages dispatched</th></tr></thead><tbody>
  @forelse($dispatchLines as $line)
   <tr><td>{{ $line->dispatch->dispatch_date->format('Y-m-d') }}</td><td>{{ $line->dispatch->number }}</td>
    <td>{{ $line->batch->batch_number }}</td><td>{{ $line->batch->order?->number ?? '—' }}</td>
    <td>{{ $line->dispatch->reference ?? '—' }}</td><td>{{ number_format($line->quantity_packages) }}</td></tr>
  @empty<tr><td colspan="6" class="empty">No finished goods have been dispatched.</td></tr>@endforelse
 </tbody></table></div>
 {{ $dispatchLines->links() }}
 <p class="muted">Every dispatch is linked to one released production batch. Only QC-approved customer returns count toward saleable batch availability.</p>
</div>
<div class="card" style="padding:18px;margin-top:18px"><h2>Customer returns</h2><p>Returns linked to the original invoice line and batch.</p><div class="table-wrap"><table><thead><tr><th>Date</th><th>Credit note</th><th>Batch</th><th>Packages</th><th>Disposition</th></tr></thead><tbody>
@forelse($returnLines as $line)<tr><td>{{ $line->creditNote->credit_date->format('Y-m-d') }}</td><td><a href="{{ route('sales.credit-notes.show',$line->creditNote) }}">{{ $line->creditNote->number }}</a></td><td>{{ $line->production_batch_id }}</td><td>{{ number_format($line->quantity_packages) }}</td><td>{{ $line->restocked?'QC approved to saleable stock':'Quarantined / not restocked' }}</td></tr>@empty<tr><td colspan="5" class="empty">No customer returns recorded.</td></tr>@endforelse
</tbody></table></div>{{ $returnLines->links() }}</div>
<script>
document.querySelectorAll('[data-confirm-dispatch]').forEach(form=>form.addEventListener('submit',async event=>{
 if(form.dataset.confirmed==='yes')return;
 event.preventDefault();
 const result=await Swal.fire({icon:'warning',title:'Post this dispatch?',text:'The warehouse balance will be reduced and the dispatch will be locked.',showCancelButton:true,confirmButtonText:'Post dispatch',cancelButtonText:'Review',confirmButtonColor:'#b42318'});
 if(result.isConfirmed){form.dataset.confirmed='yes';form.requestSubmit();}
}));
</script>
@endsection
