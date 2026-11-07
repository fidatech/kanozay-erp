@extends('layouts.kanozay')
@section('content')
<style>
.dn-panel{border:1px solid #e0e8f2;border-radius:12px;background:white;padding:21px}
.dn-panel .dn-table{overflow:auto;border:1px solid #e2eaf5;border-radius:10px}.dn-table table{min-width:790px;font-size:12px;margin:0}
.dn-table th{background:#f3f7fd;padding:12px}.dn-table td{padding:12px}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-truck-fast"></i> Delivery Notes</h1><p>Sales & Delivery / Documents linked to posted invoices</p></div>@canany(['sales.create','administration.update'])<a href="{{ route('sales.delivery-notes.create') }}" class="btn"><i class="fa-solid fa-plus"></i> Issue delivery note</a>@endcanany</div>
@if(session('success'))<div class="panel" style="color:#176d4c">{{ session('success') }}</div>@endif
<section class="dn-panel"><h2>Delivery note register</h2><p class="muted">Delivery notes document dispatch logistics and customer receipt. Sales invoicing already posts stock and receivables; issuing a note does not post either a second time.</p>
<div class="dn-table"><table><thead><tr><th>Delivery note</th><th>Invoice</th><th>Customer</th><th>Delivery date</th><th>Status</th><th>Created by</th><th>Actions</th></tr></thead><tbody>
@forelse($notes as $note)
<tr><td><strong>{{ $note->number }}</strong></td><td>{{ $note->invoice?->number }}</td><td>{{ $note->invoice?->customer?->name }}</td><td>{{ $note->delivery_date?->format('Y-m-d') }}</td><td><span class="status">{{ ucfirst($note->status) }}</span></td><td>{{ $note->creator?->name }}</td>
<td><a href="{{ route('sales.delivery-notes.show',$note) }}">View / Print</a></td></tr>
@empty<tr><td colspan="7" style="padding:30px;text-align:center">No delivery notes issued yet.</td></tr>
@endforelse
</tbody></table></div>{{ $notes->links() }}
</section>
@endsection
