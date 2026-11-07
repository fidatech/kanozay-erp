@extends('layouts.kanozay')
@section('content')
<style>
.po-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.po-card{background:#fff;border:1px solid #dbe5f0;border-radius:12px;padding:18px}.po-card small{color:#648099;display:block;margin-bottom:8px}.po-card strong{font-size:20px;color:#17466f}.po-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px}.po-form label{font-size:12px;font-weight:700;color:#486680;display:grid;gap:5px}.po-form input,.po-form select{width:100%;padding:10px;border:1px solid #d0ddeb;border-radius:7px}.po-form button{background:#1b6caa;color:#fff;border:0;border-radius:8px;padding:11px;align-self:end;cursor:pointer}.po-notice{padding:13px 16px;background:#f0f6fd;border:1px solid #d3e4f6;border-radius:8px;margin:12px 0;color:#315e81}.po-error{color:#a33325;background:#fff1ed;padding:12px;border-radius:8px}@media(max-width:900px){.po-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.po-form{grid-template-columns:1fr 1fr}}@media(max-width:600px){.po-cards,.po-form{grid-template-columns:1fr}}
</style>
@php
$currency=$order->currency?->code;
$subtotal=\Brick\Math\BigDecimal::zero();
foreach($order->items as $i){$subtotal=$subtotal->plus(\Brick\Math\BigDecimal::of((string)$i->quantity)->multipliedBy((string)$i->unit_price)->toScale(4,\Brick\Math\RoundingMode::HalfUp));}
$expenses=\Brick\Math\BigDecimal::zero();foreach($order->expenses as $expense){$expenses=$expenses->plus((string)$expense->amount);}
$advances=\Brick\Math\BigDecimal::zero();foreach($order->advances as $advance){$advances=$advances->plus((string)$advance->amount);}
$landed=$subtotal->plus($expenses);
$balance=$subtotal->minus($advances);
$hasSubtotal=$subtotal->compareTo(\Brick\Math\BigDecimal::zero())>0;
@endphp
<div class="heading"><div><h1>Purchase Order {{ $order->number }}</h1><p>{{ ucfirst($order->source) }} purchase · {{ ucfirst($order->status) }} · {{ $order->order_date }}</p></div><a href="{{ route('purchases.orders.index') }}">Back to Orders</a></div>
@if(session('success'))<div class="po-notice">{{ session('success') }}</div>@endif
@if($errors->any())<div class="po-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="panel"><h2>Purchase Details</h2><p><strong>Supplier:</strong> {{ $order->supplier?->name }} &nbsp; <strong>Currency:</strong> {{ $currency }} &nbsp; <strong>Expected delivery:</strong> {{ $order->expected_date ?? 'Not specified' }}</p><p>{{ $order->notes }}</p></div>
@if($order->status==='draft')
@can('purchases.update')
<form method="POST" action="{{ route('purchases.orders.confirm',$order) }}" style="margin:12px 0">@csrf
<button type="submit" class="btn" onclick="return confirm('Confirm this purchase order? Material links and purchase details will be locked.')">Confirm purchase order</button>
</form>
@endcan
@endif
<div class="po-cards">
<div class="po-card"><small>Items subtotal</small><strong><x-finance-amount :amount="$subtotal" /> {{ $currency }}</strong></div>
<div class="po-card"><small>Purchase expenses</small><strong><x-finance-amount :amount="$expenses" /> {{ $currency }}</strong></div>
<div class="po-card"><small>Estimated landed cost</small><strong><x-finance-amount :amount="$landed" /> {{ $currency }}</strong></div>
<div class="po-card"><small>Supplier balance (unposted)</small><strong><x-finance-amount :amount="$balance" /> {{ $currency }}</strong></div>
</div>
<div class="panel"><h2>Items and Estimated Landed Cost</h2><div class="table-wrap"><table><thead><tr><th>Description</th><th>Inventory material</th><th>Quantity</th><th>Unit Price</th><th>Line Total</th><th>Allocated Expenses</th><th>Estimated Landed Total</th></tr></thead><tbody>
@foreach($order->items as $item)
@php($line=\Brick\Math\BigDecimal::of((string)$item->quantity)->multipliedBy((string)$item->unit_price)->toScale(4,\Brick\Math\RoundingMode::HalfUp))
@php($allocated=$hasSubtotal ? $expenses->multipliedBy($line)->dividedBy($subtotal,4,\Brick\Math\RoundingMode::HalfUp) : \Brick\Math\BigDecimal::zero())
<tr><td>{{ $item->description }}</td><td>
@if($order->status==='draft')
@can('purchases.update')
<form method="POST" action="{{ route('purchases.orders.items.link',[$order,$item]) }}" style="display:flex;gap:6px;align-items:center">@csrf @method('PATCH')
<select name="inventory_material_id" aria-label="Inventory material for {{ $item->description }}" style="min-width:160px;padding:8px;border:1px solid #cddbea;border-radius:7px"><option value="">Not linked</option>@foreach($materials as $material)<option value="{{ $material->id }}" @selected($item->inventory_material_id==$material->id)>{{ $material->sku }} · {{ $material->name }} ({{ $material->unit }})</option>@endforeach</select><button class="btn" type="submit">Save</button></form>
@else {{ $item->inventoryMaterial?->name ?? 'Not linked' }} @endcan
@else {{ $item->inventoryMaterial?->name ?? 'Not linked' }} {{ $item->inventoryMaterial ? '('.$item->inventoryMaterial->unit.')' : '' }} @endif
</td><td>{{ number_format((float)$item->quantity,4) }}</td><td><x-finance-amount :amount="$item->unit_price" /></td><td><x-finance-amount :amount="$line" /></td><td>{{ $hasSubtotal ? '' : 'Pending' }}@if($hasSubtotal)<x-finance-amount :amount="$allocated" />@endif</td><td>{{ $hasSubtotal ? '' : 'Pending' }}@if($hasSubtotal)<x-finance-amount :amount="$line->plus($allocated)" />@endif</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="4">Total ({{ $currency }})</th><th><x-finance-amount :amount="$subtotal" /></th><th>{{ $hasSubtotal ? '' : 'Pending' }}@if($hasSubtotal)<x-finance-amount :amount="$expenses" />@endif</th><th>{{ $hasSubtotal ? '' : 'Pending' }}@if($hasSubtotal)<x-finance-amount :amount="$landed" />@endif</th></tr></tfoot></table></div>
<p class="muted">Estimated expenses are allocated proportionally by item value. Expense allocation is an estimate only. Goods receipts post stock quantities and preserve purchase-value snapshots without inventory valuation or finance entries. Zero-value orders cannot be allocated.</p></div>
<div class="panel">
<h2>Goods Received Notes</h2>
@canany(['purchases.view','inventory.view','administration.view'])
<p><a class="btn btn-light" href="{{ route('workspace.page',['purchases','purchase-returns']) }}">Return goods to supplier</a></p>
@endcanany
<p class="muted">Only confirmed orders can receive goods. Quantities use the linked material's unit. Posting a GRN increases inventory immediately and freezes purchase prices in the order currency. These snapshots exclude other landed costs and accounting entries. A base-currency purchase equivalent is shown only when an explicit direct historical exchange rate was available on the receipt date; it is not an inventory valuation.</p>
@if(in_array($order->status,['confirmed','partially_received'],true))
@can('inventory.update')
<form method="POST" action="{{ route('purchases.orders.receipts.store',$order) }}" style="margin:16px 0">@csrf
<input type="hidden" name="receipt_token" value="{{ old('receipt_token', (string) \Illuminate\Support\Str::uuid()) }}">
<div class="po-form">
<label>Receiving warehouse<select name="company_warehouse_id"><option value="">Unassigned stock</option>@foreach(($warehouses??collect()) as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} / {{ $warehouse->name }}</option>@endforeach</select></label>
<label>Receipt date<input type="date" name="received_on" value="{{ old('received_on',now()->toDateString()) }}" required max="{{ now()->toDateString() }}"></label>
<label>Supplier delivery reference<input type="text" name="supplier_reference" maxlength="100" value="{{ old('supplier_reference') }}"></label>
<label>Notes<input type="text" name="notes" maxlength="2000" value="{{ old('notes') }}"></label>
</div>
<div class="table-wrap"><table><thead><tr><th>PO item</th><th>Material</th><th>Ordered</th><th>Already received</th><th>Receive now</th></tr></thead><tbody>
@foreach($order->items as $item)
@php($received = $item->receiptLines->sum('quantity'))
@php($remaining = max(0, (float)$item->quantity - (float)$received))
<tr><td>{{ $item->description }}</td><td>{{ $item->inventoryMaterial?->name ?? 'Not linked' }} {{ $item->inventoryMaterial?->unit }}</td>
<td>{{ number_format((float)$item->quantity,4) }}</td><td>{{ number_format((float)$received,6) }}</td>
<td>@if($item->inventoryMaterial && $remaining > 0)
<input type="number" name="quantities[{{ $item->id }}]" step="0.000001" min="0.000001" max="{{ number_format($remaining,6,'.','') }}" value="{{ old('quantities.'.$item->id) }}" placeholder="Up to {{ number_format($remaining,6) }}" style="width:160px;padding:9px;border:1px solid #cddbea;border-radius:8px">
@else <span class="muted">{{ $remaining <= 0 ? 'Fully received' : 'No material linked' }}</span> @endif</td></tr>
@endforeach
</tbody></table></div>
<button type="submit" class="btn" style="margin-top:12px" onclick="return confirm('Post these quantities to the inventory ledger? This cannot be reversed by editing the receipt.')">Post goods receipt</button>
</form>
@endcan
@endif
<div class="table-wrap"><table><thead><tr><th>GRN number</th><th>Date</th><th>Supplier reference</th><th>Lines</th><th>Purchase value snapshot</th><th>Base purchase equivalent</th><th>Status</th></tr></thead><tbody>
@forelse($order->receipts as $receipt)

<tr><td><strong>{{ $receipt->number }}</strong></td><td>{{ $receipt->received_on->format('Y-m-d') }}</td><td>{{ $receipt->supplier_reference ?? '—' }}</td><td>{{ $receipt->lines->count() }}</td><td>{{ $receipt->purchase_value_snapshot ?? 'Not captured' }}</td><td>
{{ $receipt->base_purchase_value_snapshot ?? ($receipt->cost_currency_code ? 'FX rate unavailable' : 'Not captured') }}
@if($receipt->fx_rate_snapshot !== null)
<small style="display:block;color:#648099">Rate: {{ $receipt->fx_rate_snapshot }}@if($receipt->fx_rate_effective_date) · {{ $receipt->fx_rate_effective_date->format('Y-m-d') }}@else · base currency @endif</small>
@endif
</td><td>{{ ucfirst($receipt->status) }}</td></tr>
@empty<tr><td colspan="7" class="muted">No goods received yet.</td></tr>@endforelse
</tbody></table></div>
@foreach($order->receipts as $receipt)
@if($receipt->status === 'posted' && $receipt->cost_currency_code && $receipt->lines->isNotEmpty() && $receipt->lines->every(fn ($line) => $line->line_subtotal_snapshot !== null))
<div style="margin:14px 0;padding:14px;border:1px solid #dbe5f0;border-radius:10px">
<strong>{{ $receipt->number }} — landed-cost components ({{ $receipt->cost_currency_code }})</strong>
@php($recordedCosts = \App\Models\GoodsReceiptLandedCost::where('goods_receipt_id', $receipt->id)->with('allocations')->get())
@if($recordedCosts->isNotEmpty())
<ul>@foreach($recordedCosts as $cost)<li>{{ ucfirst($cost->component) }}: <x-finance-amount :amount="$cost->amount" /> {{ $cost->currency_code }} ({{ $cost->allocations->count() }} allocated lines)</li>@endforeach</ul>
@endif
@can('inventory.update')
@if($recordedCosts->count() < 6)
<form method="POST" action="{{ route('purchases.orders.receipts.landed-costs.store',[$order,$receipt]) }}" class="po-form">
@csrf
<label>Cost component<select name="component" required>
@foreach(['freight'=>'Freight','customs'=>'Customs','transport'=>'Transportation','handling'=>'Handling','insurance'=>'Insurance','other'=>'Other'] as $value=>$label)
@if(!$recordedCosts->contains('component',$value))<option value="{{ $value }}">{{ $label }}</option>@endif
@endforeach
</select></label>
<label>Amount ({{ $receipt->cost_currency_code }})<input type="number" name="amount" min="0.01" step="0.01" required></label>
<label>Reference<input type="text" name="reference" maxlength="100"></label>
<button type="submit" onclick="return confirm('Record this immutable allocation?')">Record allocation</button>
</form>
@endif
@endcan
<p class="muted">Amounts are allocated using frozen GRN line purchase values. This does not update stock valuation or financial accounts.</p>
</div>
@endif
@endforeach
</div>
<div class="panel"><h2>Purchase Expenses</h2>
@if($order->status==='draft')
<form method="POST" action="{{ route('purchases.orders.expenses.store',$order) }}" class="po-form">@csrf
<label>Expense category<select name="category" required>@foreach(['transport'=>'Transportation','loading'=>'Loading / unloading','customs'=>'Customs','insurance'=>'Insurance','freight'=>'Freight / shipment','handling'=>'Handling','other'=>'Other'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
<label>Description<input name="description" maxlength="255" placeholder="Optional details"></label>
<label>Amount ({{ $currency }})<input type="number" name="amount" required min="0.0001" step="0.0001"></label>
<label>Date<input type="date" name="expense_date" required value="{{ now()->toDateString() }}"></label>
<button type="submit">Add Expense</button>
</form>
@endif
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount ({{ $currency }})</th></tr></thead><tbody>@forelse($order->expenses as $expense)<tr><td>{{ $expense->expense_date }}</td><td>{{ ucfirst($expense->category) }}</td><td>{{ $expense->description }}</td><td><x-finance-amount :amount="$expense->amount" /></td></tr>@empty<tr><td colspan="4">No purchase expenses recorded.</td></tr>@endforelse</tbody></table></div></div>
<div class="panel"><h2>Supplier Advances</h2>
@if($order->status==='draft')
<form method="POST" action="{{ route('purchases.orders.advances.store',$order) }}" class="po-form">@csrf
<label>Payment date<input type="date" name="payment_date" required value="{{ now()->toDateString() }}"></label>
<label>Amount ({{ $currency }})<input type="number" name="amount" required min="0.0001" step="0.0001"></label>
<label>Reference<input name="reference" maxlength="100" placeholder="Receipt / transfer reference"></label>
<label>Notes<input name="notes" maxlength="2000"></label>
<button type="submit">Record Advance</button>
</form>
@endif
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Reference</th><th>Notes</th><th>Amount ({{ $currency }})</th></tr></thead><tbody>@forelse($order->advances as $advance)<tr><td>{{ $advance->payment_date }}</td><td>{{ $advance->reference }}</td><td>{{ $advance->notes }}</td><td><x-finance-amount :amount="$advance->amount" /></td></tr>@empty<tr><td colspan="4">No supplier advances recorded.</td></tr>@endforelse</tbody></table></div>
<div class="po-notice">Recorded advances are purchase-level tracking entries only. They do not transfer money or create accounting postings. Supplier balance excludes third-party expenses and is not a verified payable balance. All values are in the order currency.</div></div>
@endsection
