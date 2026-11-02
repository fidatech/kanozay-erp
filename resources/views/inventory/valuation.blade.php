@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-scale-balanced"></i> Raw-material valuation</h1>
 <p>Remaining FIFO cost layers. Values stay in their captured transaction currencies; amounts in different currencies are not combined.</p></div>
 <a class="btn btn-light" href="{{ route('inventory.materials.index') }}">Back to materials</a>
</div>
<div class="card" style="padding:22px">
 <form method="GET" class="filters" style="display:flex;gap:10px;align-items:end;margin-bottom:18px">
  <label>Currency code <input name="currency" value="{{ $currencyFilter }}" maxlength="3" pattern="[A-Za-z]{3}" placeholder="All currencies"></label>
  <button class="btn" type="submit">Filter</button>
  @if($currencyFilter)<a class="btn btn-light" href="{{ route('inventory.valuation') }}">Clear</a>@endif
 </form>
 <div class="table-wrap"><table>
  <thead><tr><th>SKU</th><th>Material</th><th>Unit</th><th>Quantity remaining</th><th>Cost currency</th><th>Remaining value</th><th>Valuation</th></tr></thead>
  <tbody>
   @forelse($rows as $row)
    <tr>
     <td><a href="{{ route('inventory.materials.show', $row['material']) }}"><strong>{{ $row['material']->sku }}</strong></a></td>
     <td>{{ $row['material']->name }}</td><td>{{ $row['material']->unit }}</td>
     <td>{{ $row['quantity_display'] }}</td>
     <td>{{ $row['currency_code'] ?? '—' }}</td>
     <td>{{ $row['is_valued'] ? $row['currency_code'].' '.$row['value_display'] : '—' }}</td>
     <td>{{ $row['is_valued'] ? 'Valued' : 'Unvalued' }}</td>
    </tr>
   @empty<tr><td colspan="7" class="empty">No remaining cost layers match this filter.</td></tr>@endforelse
  </tbody>
 </table></div>
 <p class="muted" style="margin-top:16px">Unvalued quantities include opening stock without a cost snapshot and physical balances recorded before valuation tracking. Reconcile these quantities against verified source documents before using inventory value in financial statements.</p>
</div>
@endsection
