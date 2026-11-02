@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Customer quotations</h1><p>Issued price quotations; issuing a quotation does not reserve inventory.</p></div>@can('sales.create')<a class="btn" href="{{ route('sales.quotations.create') }}">New quotation</a>@endcan</div>
<div class="card" style="padding:20px"><div class="table-wrap"><table><thead><tr><th>Quotation</th><th>Customer</th><th>Date</th><th>Valid until</th><th>Currency</th><th>Total</th><th>Status</th></tr></thead><tbody>
@forelse($quotations as $quotation)<tr><td><a href="{{ route('sales.quotations.show', $quotation) }}">{{ $quotation->number }}</a></td><td>{{ $quotation->customer->name }}</td><td>{{ $quotation->quotation_date->format('Y-m-d') }}</td><td>{{ $quotation->valid_until->format('Y-m-d') }}</td><td>{{ $quotation->currency_code }}</td><td>{{ $quotation->currency_code }} {{ rtrim(rtrim((string)$quotation->total,'0'),'.') }}</td><td>{{ ucfirst($quotation->status) }}</td></tr>
@empty<tr><td colspan="7" class="empty">No quotations have been issued.</td></tr>@endforelse
</tbody></table></div>{{ $quotations->links() }}</div>
@endsection
