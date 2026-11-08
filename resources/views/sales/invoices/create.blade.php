@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-file-invoice-dollar"></i> New sales invoice</h1><p>Posting creates the customer receivable and revenue journal, then dispatches the selected released batches.</p></div>
 <a class="btn btn-light" href="{{ route('sales.invoices.index') }}">Back to invoices</a>
</div>
<div class="card" style="padding:22px">
 @if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
 @if($batches->isEmpty())<p class="empty">No released finished-goods batches are available to invoice.</p>@else
 <form method="POST" action="{{ route('sales.invoices.store') }}" data-confirm-invoice>
  @csrf
  <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
  <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
   <label>Customer
    <select name="customer_id" data-create-entity="customer" required><option value="">Select customer</option>
     @foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id')==$customer->id)>{{ $customer->code ? $customer->code.' · ' : '' }}{{ $customer->name }}</option>@endforeach
    </select>
   </label>
   <label>Invoice currency
    <select name="currency_id" data-create-entity="currency" required><option value="">Select currency</option>
     @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id')==$currency->id)>{{ $currency->code }} · {{ $currency->name }}</option>@endforeach
    </select>
   </label>
   <label>Invoice date <input type="date" name="invoice_date" value="{{ old('invoice_date', now()->toDateString()) }}" required></label>
   <label>Due date <input type="date" name="due_date" value="{{ old('due_date', now()->toDateString()) }}" required></label>
   <label>Revenue account
    <select name="income_ledger_id" required><option value="">Select revenue account</option>
     @foreach($incomeAccounts as $account)<option value="{{ $account->id }}" @selected(old('income_ledger_id')==$account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach
    </select>
   </label>
   <label>Customer reference <input name="reference" maxlength="100" value="{{ old('reference') }}"></label>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin:26px 0 10px">
   <h3 style="margin:0">Invoice items</h3><button type="button" class="btn btn-light" id="add-invoice-line"><i class="fa-solid fa-plus"></i> Add item</button>
  </div>
  <div class="table-wrap"><table><thead><tr><th>Sales order line (optional)</th><th>Released batch / product</th><th>Source warehouse</th><th>Packages</th><th>Unit price</th><th></th></tr></thead>
   <tbody id="invoice-lines">
    @foreach(old('lines', [['dispatch_token'=>(string) \Illuminate\Support\Str::uuid(),'production_batch_id'=>'','quantity_packages'=>'','unit_price'=>'']]) as $index=>$line)
     <tr class="journal-line">
      <td><select name="lines[{{ $index }}][sales_order_line_id]"><option value="">Direct invoice</option>@foreach($orderLines as $orderLine)<option value="{{ $orderLine->id }}" data-finished-good="{{ $orderLine->finished_good_id }}" data-customer="{{ $orderLine->order->customer_id }}" data-currency="{{ $orderLine->order->currency_id }}" @selected(($line['sales_order_line_id']??'')==$orderLine->id)>{{ $orderLine->order->number }} · {{ $orderLine->description }} · {{ $orderLine->quantity - $orderLine->invoiceLines->sum('quantity_packages') }} remaining</option>@endforeach</select></td>
      <td><select name="lines[{{ $index }}][production_batch_id]" required><option value="">Select released batch</option>
       @foreach($batches as $batch)<option value="{{ $batch->id }}" data-good="{{ $batch->finished_good_id }}" data-available="{{ $batch->available_packages }}" @selected(($line['production_batch_id']??'')==$batch->id)>{{ $batch->batch_number }} · {{ $batch->finishedGood->sku }} — {{ $batch->finishedGood->name }} · {{ $batch->available_packages }} available</option>@endforeach
      </select><input type="hidden" name="lines[{{ $index }}][dispatch_token]" value="{{ $line['dispatch_token'] ?? (string) \Illuminate\Support\Str::uuid() }}"></td>
      <td><select name="lines[{{ $index }}][company_warehouse_id]"><option value="">Unassigned stock</option>
       @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($line['company_warehouse_id']??'')==$warehouse->id)>{{ $warehouse->code }} / {{ $warehouse->name }}</option>@endforeach
      </select></td>
      <td><input name="lines[{{ $index }}][quantity_packages]" type="text" inputmode="numeric" min="1" step="1" value="{{ $line['quantity_packages']??'' }}" required></td>
      <td><input name="lines[{{ $index }}][unit_price]" type="text" inputmode="decimal" min="0.0001" step="0.0001" value="{{ $line['unit_price']??'' }}" required></td>
      <td><button type="button" class="btn btn-light remove-invoice-line" aria-label="Remove item">Remove</button></td>
     </tr>
    @endforeach
   </tbody>
  </table></div>
  <p class="muted">Link an order line to prevent invoicing above its remaining quantity. Direct invoices remain available when no order is used. Line totals use decimal arithmetic; tax, discounts and surcharges await Kanozay’s confirmed rules. Each selected quantity is dispatched when the invoice posts.</p>
  <div style="display:flex;justify-content:flex-end;margin-top:18px"><button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Post invoice and dispatch</button></div>
 </form>
 @endif
</div>
<template id="invoice-line-template">
 <tr class="journal-line">
  <td><select data-order-line><option value="">Direct invoice</option>@foreach($orderLines as $orderLine)<option value="{{ $orderLine->id }}" data-finished-good="{{ $orderLine->finished_good_id }}" data-customer="{{ $orderLine->order->customer_id }}" data-currency="{{ $orderLine->order->currency_id }}">{{ $orderLine->order->number }} · {{ $orderLine->description }} · {{ $orderLine->quantity - $orderLine->invoiceLines->sum('quantity_packages') }} remaining</option>@endforeach</select></td>
  <td><select data-batch-select required><option value="">Select released batch</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" data-good="{{ $batch->finished_good_id }}" data-available="{{ $batch->available_packages }}">{{ $batch->batch_number }} · {{ $batch->finishedGood->sku }} — {{ $batch->finishedGood->name }} · {{ $batch->available_packages }} available</option>@endforeach</select><input data-dispatch-token type="hidden"></td>
  <td><select data-warehouse><option value="">Unassigned stock</option>
  @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} / {{ $warehouse->name }}</option>@endforeach</select></td>
  <td><input data-quantity type="text" inputmode="numeric" min="1" step="1" required></td>
  <td><input data-unit-price type="text" inputmode="decimal" min="0.0001" step="0.0001" required></td>
  <td><button type="button" class="btn btn-light remove-invoice-line" aria-label="Remove item">Remove</button></td>
 </tr>
</template>
<script>
(()=>{
 const body=document.getElementById('invoice-lines'),template=document.getElementById('invoice-line-template');
 if(!body||!template)return;
 let next=Math.max(0,...Array.from(body.querySelectorAll('select[name^="lines["]')).map(select=>Number(select.name.match(/lines\[(\d+)\]/)?.[1]||0)+1));
 const newToken=()=>window.crypto?.randomUUID?window.crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,char=>{const random=Math.random()*16|0;return(char==='x'?random:(random&0x3|0x8)).toString(16)});
 document.getElementById('add-invoice-line').addEventListener('click',()=>{
  if(body.querySelectorAll('tr').length>=100)return;
  const row=template.content.firstElementChild.cloneNode(true);
  row.querySelector('[data-order-line]').name=`lines[${next}][sales_order_line_id]`;
  row.querySelector('[data-batch-select]').name=`lines[${next}][production_batch_id]`;
  row.querySelector('[data-dispatch-token]').name=`lines[${next}][dispatch_token]`;
  row.querySelector('[data-dispatch-token]').value=newToken();
  row.querySelector('[data-warehouse]').name=`lines[${next}][company_warehouse_id]`;
  row.querySelector('[data-quantity]').name=`lines[${next}][quantity_packages]`;
  row.querySelector('[data-unit-price]').name=`lines[${next}][unit_price]`;
  next++;body.appendChild(row);
  if(window.jQuery&&jQuery.fn.select2)jQuery(row).find('select').select2({width:'100%'});
 });
 body.addEventListener('change',event=>{
  if(event.target.matches('[data-order-line]')){
   const selected=event.target.selectedOptions[0],row=event.target.closest('tr');
   if(selected.value){const batches=row.querySelector('[data-batch-select]');for(const option of batches.options){if(!option.value)continue;option.hidden=option.dataset.good!==selected.dataset.finishedGood}if(batches.selectedOptions[0]?.hidden)batches.value='';}
  }
 });
 body.addEventListener('click',event=>{if(event.target.matches('.remove-invoice-line')&&body.querySelectorAll('tr').length>1)event.target.closest('tr').remove()});
 document.querySelector('[data-confirm-invoice]')?.addEventListener('submit',async event=>{
  const form=event.currentTarget;if(form.dataset.confirmed==='yes')return;
  event.preventDefault();
  const result=await Swal.fire({icon:'warning',title:'Post this invoice?',text:'Customer receivable and revenue will be posted, and the selected batch quantities will leave the warehouse.',showCancelButton:true,confirmButtonText:'Post invoice',cancelButtonText:'Review',confirmButtonColor:'#b42318'});
  if(result.isConfirmed){form.dataset.confirmed='yes';form.requestSubmit()}
 });
})();
</script>
@endsection
