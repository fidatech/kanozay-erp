@extends('layouts.kanozay')
@section('content')
<style>
.sp-card{background:#fff;border:1px solid #dce8f3;padding:22px;border-radius:12px;margin:15px 0}
.sp-scroll{overflow:auto}.sp-scroll table{min-width:1080px;font-size:12px}
.sp-scroll td,.sp-scroll th{padding:12px}
.sp-note{padding:12px;border-radius:8px;background:#fff8e8;color:#75571b;line-height:1.65}
</style>
<div class="heading"><div><h1>Supplier Performance Scorecards</h1><p>Source-backed purchase orders, goods receipts, returns and approved evaluations</p></div>
<a class="btn btn-light" href="{{ route('workspace.page',['purchases','supplier-performance']) }}">Manual supplier evaluations</a></div>
<section class="sp-card"><form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
<label>Supplier ID <input name="supplier_id" inputmode="numeric" value="{{ $supplier_id }}" placeholder="All suppliers"></label>
<button class="btn">Apply filter</button>
<a class="btn btn-light" href="{{ route('purchases.supplier-scorecards.export',array_filter(['supplier_id'=>$supplier_id])) }}">Download CSV</a>
</form></section>
<section class="sp-card"><p class="sp-note">On-time figures count individual posted goods receipt events that have a scheduled PO arrival date. They do not claim an order's complete delivery, quality pass rate, financial performance or supplier ranking. Multiple partial receipts can belong to one order. Supplier evaluation scores are displayed only when independently approved, without inventing missing ratings. Currencies and quantities are intentionally not combined.</p>
<div class="sp-scroll"><table><thead><tr><th>Supplier</th><th>Purchase orders</th><th>Confirmed</th><th>Posted GRNs</th><th>GRNs with due date</th><th>On-time GRNs</th><th>Return records</th><th>Matched invoices</th><th>Reviewed scores</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><a href="{{ route('finance.suppliers.show',$row['supplier_id']) }}">{{ $row['name'] }}</a><br><small>{{ $row['code']??'' }}</small></td>
<td>{{ $row['orders'] }}</td><td>{{ $row['confirmed_orders'] }}</td><td>{{ $row['receipts'] }}</td><td>{{ $row['dated_receipts'] }}</td>
<td>{{ $row['on_time'] }}</td><td>{{ $row['returns'] }}</td><td>{{ $row['matched_invoices'] }}</td>
<td>@if($row['review_no'])Quality {{ $row['review_quality']??'—' }} / Delivery {{ $row['review_delivery']??'—' }}
<br><small>{{ $row['review_no'] }} · {{ $row['review_period']??'—' }}</small>
@else Not independently evaluated @endif</td></tr>
@empty<tr><td colspan="9">No supplier records found.</td></tr>@endforelse
</tbody></table></div></section>
@endsection