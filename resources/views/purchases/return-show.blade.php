@extends('layouts.kanozay')
@section('content')
<style>
.return-sheet{max-width:930px;margin:auto;background:white;border:1px solid #d8e3f0;padding:30px;border-radius:10px}
.return-sheet table{width:100%;border-collapse:collapse;font-size:12px}
.return-sheet th,.return-sheet td{padding:12px;border-bottom:1px solid #dfe7ef}
.return-sheet th{background:#eaf3fc}
@media print{@page{size:A4;margin:12mm}.top,.rail,.sidebar,.heading,.no-print{display:none!important}.return-sheet{border:0;box-shadow:none;max-width:none;padding:0}}
</style>
<div class="heading"><div><h1>Purchase return {{ $return->number }}</h1><p>Supplier return record linked to original goods receipt</p></div><div class="actions"><a class="btn btn-light" href="{{ route('workspace.page',['purchases','purchase-returns']) }}">Back to returns</a><button class="btn" onclick="window.print()">Print / Save PDF</button></div></div>
<div class="return-sheet">
<header style="display:flex;gap:18px;align-items:center;border-bottom:3px solid #277746;padding-bottom:16px;margin-bottom:20px">
<img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay" style="max-width:130px;max-height:85px"><div><h2>Kanozay Foods Manufacturing Company</h2><p>Supplier Purchase Return Note — {{ $return->number }}</p></div>
</header>
<p><strong>Supplier:</strong> {{ $return->order?->supplier?->name }} · <strong>Purchase order:</strong> {{ $return->order?->number }} · <strong>Receipt:</strong> {{ $return->receipt?->number }}</p>
<p><strong>Date:</strong> {{ $return->returned_on?->toDateString() }} · <strong>Supplier reference:</strong> {{ $return->supplier_reference??'—' }}</p>
<p><strong>Reason:</strong> {{ $return->reason }}</p>
<table><thead><tr><th>#</th><th>Raw material</th><th>Quantity</th><th>Unit</th><th>Source unit cost</th><th>Expected supplier credit</th></tr></thead><tbody>
@php($total=\Brick\Math\BigDecimal::zero())
@php($hasAllCosts=true)
@foreach($return->lines as $line)
@php($total=$line->supplier_credit_value_snapshot===null?$total:$total->plus((string)$line->supplier_credit_value_snapshot))
@php($hasAllCosts=$hasAllCosts && $line->supplier_credit_value_snapshot!==null)
<tr><td>{{ $loop->iteration }}</td><td>{{ $line->material?->name }}</td><td>{{ $line->quantity }}</td><td>{{ $line->material?->unit }}</td><td>{{ $line->unit_price_snapshot??'Not captured' }}</td><td>{{ $line->supplier_credit_value_snapshot??'Unvalued' }}</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="5">Estimated total ({{ $return->currency?->code }})</th><th>{{ $hasAllCosts?(string)$total->toScale(4,\Brick\Math\RoundingMode::HalfUp):'Incomplete source costs' }}</th></tr></tfoot></table>
<p style="background:#fff8e6;padding:12px">Inventory was reduced when posted. A supplier credit and payable adjustment must be reviewed separately. The amounts above use original purchase-price snapshots and exclude customs, freight, landed costs, settlement and FX changes.</p>
<p><strong>Status:</strong> {{ $return->financial_status==='supplier_credit_posted'?'Supplier debit note posted':'Supplier credit pending' }}</p>
@canany(['finance.update','administration.update'])
@if($return->financial_status==='supplier_credit_pending')<p class="no-print"><a class="btn btn-light" href="{{ route('purchases.supplier-debit-notes.index') }}">Post finance-reviewed supplier debit note</a></p>@endif
@endcanany
<div style="margin-top:65px;display:flex;justify-content:space-between"><span>Warehouse officer: ___________________</span><span>Supplier acknowledgment: ___________________</span></div>
</div>
@endsection
