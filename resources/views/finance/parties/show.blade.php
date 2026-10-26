@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1><i class="fa-solid {{ $type==='customer'?'fa-users':'fa-truck-field' }}"></i> {{ $party->name }}</h1><p>{{ ucfirst($type) }} profile &amp; financial overview</p></div><a class="btn" href="{{ route($type==='customer'?'finance.customers.index':'finance.suppliers.index') }}">Back to {{ ucfirst($type) }}s</a></div>
<div class="panel"><h2>Contact &amp; Account Details</h2><div class="table-wrap"><table><tbody><tr><th>Code</th><td>{{ $party->code ?: '—' }}</td><th>Status</th><td>{{ $party->is_active?'Active':'Inactive' }}</td></tr><tr><th>Contact person</th><td>{{ $party->contact_person ?: '—' }}</td><th>Phone</th><td>{{ $party->phone ?: '—' }}</td></tr><tr><th>Email</th><td>{{ $party->email ?: '—' }}</td><th>Address</th><td>{{ $party->address ?: '—' }}</td></tr><tr><th>Notes</th><td colspan="3">{{ $party->notes ?: '—' }}</td></tr></tbody></table></div></div>
@can('administration.update')
<div class="panel"><h2>Edit {{ ucfirst($type) }} Profile</h2>@if($errors->any())<p style="color:#b42318">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route($type==='customer'?'finance.customers.update':'finance.suppliers.update',$party) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px">@csrf @method('PATCH')
<label>Name <input name="name" required maxlength="180" value="{{ old('name',$party->name) }}"></label>
<label>Code <input name="code" maxlength="40" value="{{ old('code',$party->code) }}"></label>
<label>Contact person <input name="contact_person" maxlength="150" value="{{ old('contact_person',$party->contact_person) }}"></label>
<label>Phone <input name="phone" maxlength="80" value="{{ old('phone',$party->phone) }}"></label>
<label>Email <input type="email" name="email" maxlength="180" value="{{ old('email',$party->email) }}"></label>
<label>Address <input name="address" maxlength="2000" value="{{ old('address',$party->address) }}"></label>
<label style="grid-column:1/-1">Notes <textarea name="notes" rows="3" maxlength="2000">{{ old('notes',$party->notes) }}</textarea></label>
<div><button type="submit" class="btn">Save Profile</button></div></form></div>
@endcan
<div class="panel"><h2>Enabled Currencies</h2><p>{{ $party->currencies->pluck('code')->join(', ') ?: 'No currencies enabled' }}</p></div>
<div class="panel"><h2>Opening Balances</h2><div class="table-wrap"><table><thead><tr><th>Currency</th><th>Amount</th><th>Direction</th></tr></thead><tbody>@forelse($openingBalances as $balance)<tr><td>{{ $party->currencies->firstWhere('id',$balance->currency_id)?->code ?: $balance->currency_id }}</td><td>{{ number_format(abs((float)$balance->amount),4) }}</td><td>{{ (float)$balance->amount >= 0 ? ($type==='customer'?'They owe us':'Advance paid') : ($type==='customer'?'Customer advance':'We owe them') }}</td></tr>@empty<tr><td colspan="3">No opening balances recorded.</td></tr>@endforelse</tbody></table></div></div>
<div class="panel"><h2>Recent {{ $type==='customer'?'Receivables':'Payables' }}</h2><div class="table-wrap"><table><thead><tr><th>Document</th><th>Date</th><th>Due date</th><th>Amount</th><th>Currency</th></tr></thead><tbody>@forelse($documents as $document)<tr><td>{{ $document->number }}</td><td>{{ $document->document_date }}</td><td>{{ $document->due_date }}</td><td>{{ number_format((float)$document->amount,4) }}</td><td>{{ $party->currencies->firstWhere('id',$document->currency_id)?->code ?: $document->currency_id }}</td></tr>@empty<tr><td colspan="5">No financial documents recorded.</td></tr>@endforelse</tbody></table></div><p class="muted">Showing up to 20 recent documents.</p></div>
@endsection