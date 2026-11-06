@extends('layouts.kanozay')
@section('content')
<style>
.ps-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ps-card{background:#fff;border:1px solid #dbe7f3;border-radius:13px;padding:19px;margin-top:17px}
.ps-filters{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.ps-filters select{min-width:140px;min-height:40px;padding:9px 12px;border:1px solid #d8e4ef;border-radius:9px}
.ps-table{width:100%;border-collapse:collapse;font-size:12px}
.ps-table th{background:#f3f7fc;text-align:left;padding:12px;color:#55708d;white-space:nowrap}
.ps-table td{padding:12px;border-bottom:1px solid #e9eef5;vertical-align:middle}
.ps-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ps-summary{display:flex;gap:11px;flex-wrap:wrap;margin-top:15px}
.ps-balance{border:1px solid #dce7f2;border-radius:10px;padding:12px 16px;min-width:190px}
.ps-balance small{display:block;color:#71849b;margin-bottom:5px}
.ps-empty{text-align:center;color:#8795a7;padding:30px}
@media(max-width:680px){.ps-table{min-width:700px}}
</style>
<div class="heading ps-head">
 <div><h1><i class="fa-solid fa-file-lines"></i> {{ $party->name }} — Statement</h1><p>{{ ucfirst($party->type) }} account · posted transactions only</p></div>
 <a class="btn btn-light" href="{{ route($party->type==='customer'?'finance.customers.index':'finance.suppliers.index') }}">Back to {{ ucfirst($party->type) }}s</a>
</div>
<section class="ps-card">
 <form class="ps-filters" method="GET" action="{{ route('finance.parties.statement',$party) }}">
  <label for="ps-currency">Currency</label><select name="currency_id" id="ps-currency"><option value="">All currencies</option>
  @foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((int)($filters['currency_id']??0)===$currency->id)>{{ $currency->code }}</option>@endforeach
  </select><button class="btn" type="submit">Filter</button>
 </form>
 <div class="ps-summary">
 @foreach($currencies as $currency)
  @if(empty($filters['currency_id']) || (int)$filters['currency_id']===$currency->id)
   @php($value=$running[$currency->id]??\Brick\Math\BigDecimal::zero())
   <div class="ps-balance"><small>{{ $currency->code }} balance</small>
    <strong>{{ $value->abs()->toScale(2,\Brick\Math\RoundingMode::HalfUp) }}</strong>
    <span>{{ $value->isZero()?'Settled':($value->isPositive()?($party->type==='customer'?'They owe us':'We owe them'):($party->type==='customer'?'We owe them':'They owe us')) }}</span>
   </div>
  @endif
 @endforeach
 </div>
 <div class="table-wrap" style="margin-top:16px"><table class="ps-table">
  <thead><tr><th>Date</th><th>Journal No.</th><th>Description</th><th>Currency</th><th class="ps-num">Debit</th><th class="ps-num">Credit</th><th class="ps-num">Running balance</th></tr></thead>
  <tbody>
  @forelse($rows as $row)
   <tr><td>{{ $row['entry']->entry_date }}</td><td>{{ $row['entry']->number }}</td><td>{{ $row['entry']->description }}</td>
   <td>{{ $currencies[$row['entry']->currency_id]->code ?? '—' }}</td>
   <td class="ps-num">{{ \Brick\Math\BigDecimal::of((string)$row['entry']->debit)->toScale(2,\Brick\Math\RoundingMode::HalfUp) }}</td>
   <td class="ps-num">{{ \Brick\Math\BigDecimal::of((string)$row['entry']->credit)->toScale(2,\Brick\Math\RoundingMode::HalfUp) }}</td>
   <td class="ps-num">{{ $row['balance']->abs()->toScale(2,\Brick\Math\RoundingMode::HalfUp) }} {{ $row['balance']->isNegative()?'−':($row['balance']->isPositive()?'+':'') }}</td></tr>
  @empty<tr><td class="ps-empty" colspan="7">No posted ledger entries for this account.</td></tr>
  @endforelse
  </tbody>
 </table></div>
</section>
@endsection
