@extends('layouts.kanozay')
@section('content')
<style>
.ud-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.ud-head p{margin:6px 0 0;color:#6f839b;font-size:13px}
.ud-links{display:flex;gap:8px;flex-wrap:wrap}.ud-links a{text-decoration:none;padding:9px 12px;border:1px solid #d7e3f2;border-radius:8px;color:#21578d;font-size:12px;background:#fff;font-weight:700}
.ud-panel{border:1px solid #dce7f3;border-radius:14px;background:#fff;padding:18px;margin:18px 0}
.ud-filters{display:grid;grid-template-columns:minmax(150px,1fr) minmax(135px,190px) minmax(115px,165px) auto auto;gap:9px;align-items:center}
.ud-filters input,.ud-filters select{width:100%;min-width:0;box-sizing:border-box;min-height:41px;border:1px solid #d5e2f0;border-radius:9px;padding:9px 12px;background:#fff}
.ud-filters button,.ud-filters .ud-reset{display:inline-flex;justify-content:center;align-items:center;border:1px solid #c9dcf0;border-radius:9px;min-height:41px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap;cursor:pointer}
.ud-filters button{background:#1460a6;color:#fff;border-color:#1460a6}.ud-filters .ud-reset{background:#f7f9fd;color:#396185}
.ud-table{width:100%;border-collapse:collapse;font-size:12px}.ud-table th{background:#f3f7fc;color:#58708e;text-align:left;font-size:11px;padding:12px;white-space:nowrap}.ud-table td{padding:12px;border-bottom:1px solid #ecf1f7;vertical-align:middle}
.ud-table td strong{color:#183d63}.ud-type{display:inline-block;background:#eef4fb;color:#255783;padding:5px 9px;border-radius:7px;font-size:11px;font-weight:700}
.ud-muted{color:#8493a7;font-size:11px}.ud-code{font-family:ui-monospace,monospace;color:#4c6580}.ud-actions{display:flex;gap:8px;flex-wrap:wrap}.ud-actions a{color:#125c9e;font-weight:700;text-decoration:none;white-space:nowrap}
.ud-balance{font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:750}.ud-empty{text-align:center;padding:36px;color:#7c8ba0}
@media(max-width:930px){.ud-filters{grid-template-columns:minmax(150px,1fr) minmax(120px,1fr) minmax(100px,1fr) auto auto}}
@media(max-width:690px){.ud-filters{grid-template-columns:1fr 1fr}.ud-filters input{grid-column:1/-1}}
</style>
<div class="heading ud-head">
 <div><h1><i class="fa-solid fa-address-book" style="color:#137e9a"></i> All Accounts Directory</h1><p>Customers, suppliers, cashbooks, banks, Sarafis and other linked ledger accounts in one place.</p></div>
 <div class="ud-links"><a href="{{ route('finance.customers.index') }}">Customers</a><a href="{{ route('finance.suppliers.index') }}">Suppliers</a><a href="{{ route('finance.accounts.index') }}">Financial Accounts</a><a href="{{ route('admin.accounts.index') }}">Chart of Accounts</a></div>
</div>
<section class="ud-panel">
 <form method="GET" action="{{ route('finance.directory.index') }}" class="ud-filters">
  <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Search name or account code" aria-label="Search all accounts">
  <select name="type" aria-label="Account category"><option value="">All account categories</option>
   @foreach(['customer'=>'Customers','supplier'=>'Suppliers','cashbox'=>'Cashbooks','bank'=>'Banks','sarafi'=>'Sarafis','other'=>'Other mapped accounts'] as $value=>$label)
    <option value="{{ $value }}" @selected(($filters['type']??'')===$value)>{{ $label }}</option>
   @endforeach
  </select>
  <select name="currency_id" aria-label="Currency"><option value="">All currencies</option>
   @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((int)($filters['currency_id']??0)===$currency->id)>{{ $currency->code }}</option>@endforeach
  </select>
  <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <a class="ud-reset" href="{{ route('finance.directory.index') }}">Clear</a>
 </form>
 <div class="table-wrap" style="margin-top:16px"><table class="ud-table">
  <thead><tr><th>Account</th><th>Category</th><th>Currency</th><th>Purpose</th><th>Ledger Code</th><th>Posted Balance</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
   @forelse($accounts as $account)
    @php
     $entry=$balances->get($account->ledger_id.':'.$account->currency_id);
     $debit=\Brick\Math\BigDecimal::of((string)($entry->total_debit??'0'));
     $credit=\Brick\Math\BigDecimal::of((string)($entry->total_credit??'0'));
     $net=$debit->minus($credit);
     $direction=$net->isZero()?'—':($net->isPositive()?'Dr':'Cr');
     $name=$account->party_name??$account->financial_name??$account->ledger_name;
     $active=($account->party_active??$account->financial_active??$account->ledger_active);
    @endphp
    <tr>
     <td><strong>{{ $name }}</strong><div class="ud-muted">{{ $account->ledger_name }}</div></td>
     <td><span class="ud-type">{{ ucfirst($account->entity_type) }}</span></td>
     <td><strong>{{ $account->currency_code }}</strong></td>
     <td>{{ ucfirst($account->purpose) }}</td>
     <td><span class="ud-code">{{ $account->code }}</span></td>
     <td><span class="ud-balance">{{ $net->abs()->toScale(2,\Brick\Math\RoundingMode::HalfUp) }} {{ $direction }}</span></td>
     <td>{{ $active?'Active':'Inactive' }}</td>
     <td><div class="ud-actions">
      <a href="{{ route('finance.ledger.index',['account_id'=>$account->ledger_id,'currency_id'=>$account->currency_id]) }}">Ledger</a>
      @if(in_array($account->entity_type,['customer','supplier'],true) && in_array($account->purpose,['receivable','payable'],true))
       <a href="{{ route('finance.journals.index',['trade_party_id'=>$account->entity_id]) }}">Credit / Debit</a>
      @elseif(in_array($account->entity_type,['cashbox','bank','sarafi'],true))
       <a href="{{ route('finance.money.index') }}">Money Movements</a>
      @endif
     </div></td>
    </tr>
   @empty
    <tr><td colspan="8"><div class="ud-empty">No linked accounts found for these filters.</div></td></tr>
   @endforelse
  </tbody>
 </table></div>
 <div style="margin-top:13px">{{ $accounts->onEachSide(1)->links('vendor.pagination.kanozay') }}</div>
</section>
@endsection
