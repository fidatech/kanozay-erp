@extends('layouts.kanozay')
@section('content')
<style>
.finance-overview .fo-hero{background:linear-gradient(110deg,#123761,#246ba5);padding:25px;border-radius:13px;color:#fff;margin-bottom:17px}
.finance-overview .fo-hero h1{font-size:23px;margin:0 0 8px}.finance-overview .fo-hero p{margin:0;color:#dbeafa}
.finance-overview .fo-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:19px}
.finance-overview .fo-stat{background:white;border:1px solid #dce7f3;border-radius:11px;padding:20px}
.finance-overview .fo-stat small{color:#667f99;display:block;margin-bottom:9px}.finance-overview .fo-stat strong{font-size:25px;color:#17385e}
.finance-overview h2{font-size:15px;margin:22px 0 13px;color:#17385e}
.finance-overview .fo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.finance-overview .fo-grid a{padding:19px;border:1px solid #dce7f3;border-radius:11px;background:white;display:flex;align-items:center;gap:13px;font-weight:700}
.finance-overview .fo-grid i{color:#2166a0;font-size:17px}
@media(max-width:800px){.finance-overview .fo-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:450px){.finance-overview .fo-stats{grid-template-columns:1fr}}
</style>
<div class="finance-overview">
<div class="fo-hero"><h1>Finance &amp; Accounting Overview</h1><p>Financial records, customer and supplier accounts, and daily accounting workflows.</p></div>
<div class="fo-stats"><div class="fo-stat"><small>Active customers</small><strong>{{ number_format($customers) }}</strong></div><div class="fo-stat"><small>Active suppliers</small><strong>{{ number_format($suppliers) }}</strong></div><div class="fo-stat"><small>Receivable documents</small><strong>{{ number_format($receivables) }}</strong></div><div class="fo-stat"><small>Payable documents</small><strong>{{ number_format($payables) }}</strong></div></div>
<h2>Daily operations</h2>
<div class="fo-grid">
<a href="{{ route('finance.customers.index') }}"><i class="fa-solid fa-users"></i>Customers</a>
<a href="{{ route('finance.suppliers.index') }}"><i class="fa-solid fa-truck-field"></i>Suppliers</a>
<a href="{{ route('finance.journals.index') }}"><i class="fa-solid fa-book"></i>Journal Entries</a>
<a href="{{ route('finance.accounts.index') }}"><i class="fa-solid fa-building-columns"></i>Financial Accounts</a>
<a href="{{ route('finance.money.index') }}"><i class="fa-solid fa-money-bill-transfer"></i>Receipts &amp; Payments</a>
<a href="{{ route('finance.documents.index') }}"><i class="fa-solid fa-file-invoice-dollar"></i>Receivables &amp; Payables</a>
<a href="{{ route('finance.exchanges.index') }}"><i class="fa-solid fa-arrow-right-arrow-left"></i>Currency Exchange</a>
<a href="{{ route('finance.ledger.index') }}"><i class="fa-solid fa-list-ol"></i>General Ledger</a>
<a href="{{ route('finance.reconciliation.index') }}"><i class="fa-solid fa-scale-balanced"></i>Settlement Reconciliation</a>
</div>
<p style="color:#7086a0;margin-top:18px">Counts represent actual registered records, not monetary balances. Multi-currency totals are intentionally not combined.</p>
</div>
@endsection