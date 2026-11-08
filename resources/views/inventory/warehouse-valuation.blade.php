@extends('layouts.kanozay')
@section('content')
<style>
.lv-panel{background:#fff;border:1px solid #dce6f0;border-radius:12px;padding:20px;margin:14px 0}
.lv-panel th,.lv-panel td{padding:11px;font-size:12px}
.lv-note{padding:12px;background:#fff8e9;color:#76501b;border:1px solid #efdab9;border-radius:8px;line-height:1.65;font-size:12px}
.lv-row-warn{color:#a35b16;font-weight:700}
.lv-row-error{color:#ad3030;font-weight:700}
.lv-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.lv-filters input{padding:10px;border:1px solid #dce6f0;border-radius:8px}
</style>
<div class="heading">
 <div><h1>Warehouse FIFO valuation</h1>
 <p>Remaining source lots and their physically verified locations, without converting between currencies.</p></div>
 <a class="btn btn-light" href="{{ route('inventory.warehouse-stock.index') }}">Warehouse movements</a>
</div>
<section class="lv-panel">
<p class="lv-note">Costs are allocated from each remaining FIFO layer to its verified physical positions. Unreconciled layers retain an explicit unknown-location balance. Any layer whose attributed positions exceed its remaining quantity is flagged, and its location valuation is withheld until reviewed. No GL adjustment is posted by this report.</p>
<form method="GET" action="{{ route('inventory.warehouse-valuation.index') }}" class="lv-filters">
<label>Purchase currency<br><input type="text" name="currency" value="{{ $currencyFilter }}" maxlength="3" placeholder="All currencies"></label>
<button class="btn" type="submit">Filter</button>
@if($currencyFilter)<a class="btn btn-light" href="{{ route('inventory.warehouse-valuation.index') }}">Clear</a>@endif
</form>
<div class="table-wrap"><table style="min-width:900px">
<thead><tr><th>Material</th><th>FIFO lot</th><th>Source</th><th>Receipt date</th><th>Location</th><th>Remaining quantity</th><th>Currency</th><th>Remaining cost</th><th>Condition</th></tr></thead>
<tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('inventory.materials.show',$row['material']) }}">{{ $row['material']?->sku }}</a><div>{{ $row['material']?->name }}</div></td>
<td>#{{ $row['layer_id'] }}</td>
<td>{{ $row['source'] }}</td>
<td>{{ $row['received_on'] }}</td>
<td>{{ $row['location'] }}</td>
<td>{{ $row['quantity'] }} {{ $row['material']?->unit }}</td>
<td>{{ $row['currency_code']??'—' }}</td>
<td>{{ $row['value']===null?'Not valued':$row['value'] }}</td>
<td class="{{ $row['status']==='Location mismatch'?'lv-row-error':($row['status']==='Reconciliation required'?'lv-row-warn':'') }}">{{ $row['status'] }}</td>
</tr>
@empty<tr><td colspan="9">No remaining FIFO cost layers match this currency.</td></tr>
@endforelse
</tbody>
</table></div>
<div style="margin-top:15px">{{ $layers->links() }}</div>
<p class="muted">Each page covers up to 40 FIFO layers, including all their warehouse positions. The amounts are not totaled across currencies. Historical physical stock with no cost layer remains a separate inventory migration exception and cannot be assigned a purchase cost automatically.</p>
</section>
@endsection
