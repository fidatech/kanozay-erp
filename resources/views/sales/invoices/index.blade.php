@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1><i class="fa-solid fa-file-invoice-dollar"></i> Sales invoices</h1><p>Posted customer invoices, receivables and related batch dispatches.</p></div>
 @can('sales.create')<a class="btn" href="{{ route('sales.invoices.create') }}"><i class="fa-solid fa-plus"></i> New invoice</a>@endcan
</div>
<div class="card" style="padding:20px">
 @if(session('success'))<p role="status" style="color:#08734b">{{ session('success') }}</p>@endif
 <div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Customer</th><th>Invoice date</th><th>Due date</th><th>Currency</th><th>Total</th><th>Status</th></tr></thead><tbody>
  @forelse($invoices as $invoice)
   <tr><td><a href="{{ route('sales.invoices.show', $invoice) }}"><strong>{{ $invoice->number }}</strong></a></td><td>{{ $invoice->customer->name }}</td>
    <td>{{ $invoice->invoice_date->format('Y-m-d') }}</td><td>{{ $invoice->due_date->format('Y-m-d') }}</td>
    <td>{{ $invoice->currency_code }}</td><td>{{ $invoice->currency_code }} {{ rtrim(rtrim((string) $invoice->total, '0'), '.') }}</td><td>{{ ucfirst($invoice->status) }}</td></tr>
  @empty<tr><td colspan="7" class="empty">No sales invoices have been posted yet.</td></tr>@endforelse
 </tbody></table></div>
 {{ $invoices->links() }}
</div>
@endsection
