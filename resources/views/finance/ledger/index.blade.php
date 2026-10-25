@extends('layouts.kanozay')
@section('content')
<style>
.gl-workspace{padding:0!important;overflow:hidden;min-width:0}
.gl-workspace-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:17px 20px;border-bottom:1px solid #e4edf5}
.gl-workspace-header h2{margin:0!important;padding:0!important;border:0!important;font-size:15px!important;color:#1e4066}
.gl-workspace-header p{margin:5px 0 0;color:#7289a3;font-size:12px}
.gl-count{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;background:#f0f6fd;border:1px solid #dbe8f6;border-radius:20px;color:#365f88;font-size:11px;font-weight:750}
.gl-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}
.gl-filters label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#315077;min-width:0}
.gl-filters select,.gl-filters input{height:44px;width:100%;min-width:0;box-sizing:border-box;border:1px solid #d7e3f1;border-radius:9px;padding:8px 11px;background:white;color:#253e64}
.gl-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:17px 20px;border-bottom:1px solid #e5edf6}
.gl-summary>div{min-width:0;border:1px solid #dfeaf5;border-radius:11px;padding:15px 16px;background:#f8fbff}
.gl-summary strong{display:block;margin-top:9px;font-size:18px;color:#173e6b;overflow-wrap:anywhere}
.gl-muted{font-size:12px;color:#6a7e99}.gl-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.gl-link{color:#1769b2;font-weight:750}
.gl-workspace .table-wrap{max-width:100%;overflow-x:auto;margin:0 20px 18px!important;width:calc(100% - 40px)!important;border:1px solid #dfe8f3!important;border-radius:10px!important;background:#fff}
.gl-workspace table{min-width:980px;width:100%;border-collapse:separate;border-spacing:0}
.gl-workspace thead th{padding:15px 16px!important;background:#f2f6fc!important;color:#526c87!important;font-size:10px!important;text-transform:uppercase;letter-spacing:.35px;white-space:nowrap}
.gl-workspace tbody td{padding:15px 16px!important;vertical-align:middle!important;font-size:12px;border-bottom:1px solid #e9eff6!important}
.gl-workspace tbody tr:hover{background:#f8fbff}
.gl-account{font-weight:700;color:#2b4c71}.gl-date{white-space:nowrap;color:#617a94}.gl-memo{max-width:260px;overflow-wrap:anywhere;color:#647a92}
.gl-workspace .gl-pagination{padding:12px 20px}
@media(max-width:1000px){.gl-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.gl-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.gl-filters,.gl-summary{grid-template-columns:1fr}.gl-workspace-header{padding:15px}.gl-summary{padding:12px}.gl-workspace table{min-width:900px}.gl-workspace .table-wrap{margin:0 12px 14px!important;width:calc(100% - 24px)!important}}

/* Institutional transaction register: legible account hierarchy and compact numeric cells. */
.gl-workspace .table-wrap{border:0!important}
.gl-workspace table{table-layout:auto;min-width:1040px}
.gl-workspace thead th{position:sticky;top:0;z-index:1;border-bottom:1px solid #d9e5f2!important}
.gl-workspace tbody tr:nth-child(even){background:#fcfdff}
.gl-workspace tbody tr:hover{background:#edf6ff!important}
.gl-workspace tbody td{line-height:1.5}
.gl-workspace .gl-date{font-size:11px;font-weight:650}
.gl-journal-pill{display:inline-flex;align-items:center;gap:7px;border:1px solid #d7e8f8;background:#f2f8ff;padding:6px 9px;border-radius:7px;white-space:nowrap;font-size:11px;text-decoration:none}
.gl-journal-pill:hover{background:#e5f2ff;border-color:#a8ccec}
.gl-account{min-width:185px}
.gl-account-code{display:block;color:#6b86a2;font-size:10px;font-weight:700;letter-spacing:.2px}
.gl-account-name{display:block;color:#24486f;font-size:12px;font-weight:750;margin-top:2px}
.gl-memo{min-width:160px;max-width:310px;line-height:1.6!important}
.gl-currency-chip{display:inline-block;border:1px solid #dce8f5;background:#f5f9fd;color:#41688e;border-radius:6px;padding:4px 7px;font-size:10px;font-weight:800}
.gl-workspace .gl-num{min-width:110px}
.gl-workspace .gl-running{background:#f8fbff;font-weight:800;border-left:1px solid #e4edf7;padding-right:22px!important}
.gl-workspace .gl-zero{color:#b0bed0;font-weight:500}
.gl-workspace .gl-num .k-finance-amount{justify-content:flex-end;font-size:12px}
.gl-workspace .gl-pagination{border-top:1px solid #e6edf5}

/* Page-specific hierarchy: compact currency strips and inset transaction register. */
.gl-workspace{border-radius:13px!important;border-color:#dce7f3!important;box-shadow:0 4px 20px #193b5b08!important}
.gl-workspace-header{padding:20px 22px!important}
.gl-workspace-header h2{font-size:17px!important;letter-spacing:-.25px}
.gl-summary{display:grid!important;grid-template-columns:1fr!important;gap:10px!important;padding:17px 20px!important;background:#fbfdff;border-bottom:1px solid #e7eef6}
.gl-summary>.gl-currency-summary{display:flex;align-items:center;gap:20px;min-width:0;padding:13px 17px!important;border:1px solid #dfe9f4;border-radius:10px;background:white!important}
.gl-currency-title{display:flex;align-items:center;gap:11px;flex:0 0 190px;min-width:0}
.gl-currency-emblem{display:grid;place-items:center;width:38px;height:38px;flex-shrink:0;background:#eaf3fd;color:#2066a8;border-radius:10px;font-size:15px}
.gl-currency-title strong{display:block!important;margin:0!important;font-size:14px!important;color:#1e4167}
.gl-currency-title small{display:block;margin-top:3px;font-size:11px;color:#8093aa;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gl-summary-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;flex:1;min-width:0}
.gl-summary-metrics>div{min-width:0;border-left:1px solid #e7eef6;padding-left:17px}
.gl-summary-metrics span{display:block;font-size:11px;color:#6d8199}
.gl-summary-metrics strong{display:block;margin-top:6px;font-size:15px!important;overflow-wrap:anywhere}
.gl-summary-empty{font-size:12px;color:#6b819b;padding:10px}
.gl-table-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:20px 21px 12px}
.gl-table-heading h3{margin:0!important;padding:0!important;border:0!important;font-size:13px!important;color:#1c426a}
.gl-table-heading p{margin:5px 0 0;font-size:11px;color:#7a8ea5}
.gl-table-hint{font-size:10px;color:#8296ae}
.gl-workspace .table-wrap{border:1px solid #dce6f2!important;border-radius:10px!important;box-shadow:0 2px 7px #183a5c05}
.gl-workspace thead th{background:#edf3fa!important;color:#486380!important}
.gl-workspace tbody tr:last-child td{border-bottom:0!important}
.gl-workspace .gl-pagination{margin:0 20px 10px;padding:12px 0!important;border-top:0!important}
@media(max-width:850px){.gl-summary>.gl-currency-summary{align-items:stretch;flex-direction:column;gap:12px}.gl-currency-title{flex:auto}.gl-summary-metrics{width:100%}}
@media(max-width:560px){.gl-summary{padding:12px!important}.gl-summary-metrics{grid-template-columns:1fr;gap:10px}.gl-summary-metrics>div{border-left:0;border-top:1px solid #e7eef6;padding:10px 0 0}.gl-table-heading{padding:16px 13px 12px}.gl-table-hint{display:none}}

/* Finance summary: color the amount and arrow only, not the metric label. */
.gl-summary-metrics strong .k-finance-amount{display:inline-flex;align-items:center;gap:6px;font-size:16px;font-weight:800;font-variant-numeric:tabular-nums}
.gl-summary-metrics strong .k-finance-amount i{font-size:12px}
.gl-summary-metrics strong .k-finance-amount--in{color:#16803d!important}
.gl-summary-metrics strong .k-finance-amount--out{color:#dc2626!important}
.gl-summary-metrics strong .k-finance-amount--neutral{color:#244b76!important}
</style>
<div class="heading"><div><h1><i class="fa-solid fa-list-ol" style="color:#2266ad"></i> General Ledger</h1><p>Posted accounting movements, opening balances and currency-specific running balances. Select an account for a meaningful account balance.</p></div></div>
<section class="panel k-collapsible-filters"><details class="k-filter-toggle"><summary><span><i class="fa-solid fa-sliders"></i> Filter ledger entries</span><span class="k-filter-toggle-hint">Account, currency and dates <i class="fa-solid fa-chevron-down"></i></span></summary><form class="gl-filters" method="GET" action="{{ route('finance.ledger.index') }}"><label>Ledger Account<select name="account_id"><option value="">All accounts</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($account?->id===$a->id)>{{ $a->code }} — {{ $a->name }}</option>@endforeach</select></label><label>Currency<select name="currency_id"><option value="">All currencies (separate balances)</option>@foreach($currencies as $c)<option value="{{ $c->id }}" @selected($currency?->id===$c->id)>{{ $c->code }} — {{ $c->name }}</option>@endforeach</select></label><label>From<input type="date" name="from" value="{{ $filters['from']??'' }}"></label><label>To<input type="date" name="to" value="{{ $filters['to']??'' }}"></label><div class="k-filter-actions"><a class="btn btn-light" href="{{ route('finance.ledger.index') }}"><i class="fa-solid fa-arrow-rotate-left"></i> Reset</a><button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Apply Filters</button></div></form></details></section>
<section class="panel gl-workspace"><div class="gl-workspace-header"><div><h2>Ledger Transactions</h2><p>Posted journal lines with currency-specific running balances</p></div><span class="gl-count"><i class="fa-solid fa-list"></i> {{ $rows->total() }} entries</span></div><div class="gl-summary" aria-label="Ledger totals by currency">@forelse($currencies->filter(fn($c)=>$totals->contains('currency_id',$c->id)||$openingMap->has($c->id))->values() as $summaryCurrency)@php($total=$totals->firstWhere('currency_id',$summaryCurrency->id))<div class="gl-currency-summary"><div class="gl-currency-title"><span class="gl-currency-emblem"><i class="fa-solid fa-coins" aria-hidden="true"></i></span><div><strong>{{ $summaryCurrency->code }}</strong><small>{{ $summaryCurrency->name }}</small></div></div><div class="gl-summary-metrics"><div><span>Period debits</span><strong><x-finance-amount :amount="$total?->debit_total??0" direction="in" /></strong></div><div><span>Period credits</span><strong><x-finance-amount :amount="$total?->credit_total??0" direction="out" /></strong></div><div><span>Opening net debit</span><strong><x-finance-amount :amount="(($openingMap[$summaryCurrency->id]->debit_total??0)-($openingMap[$summaryCurrency->id]->credit_total??0))" :direction="(($openingMap[$summaryCurrency->id]->debit_total??0)-($openingMap[$summaryCurrency->id]->credit_total??0)) > 0 ? 'in' : (((($openingMap[$summaryCurrency->id]->debit_total??0)-($openingMap[$summaryCurrency->id]->credit_total??0)) < 0) ? 'out' : 'neutral')" /></strong></div></div></div>@empty<div class="gl-summary-empty"><i class="fa-regular fa-folder-open"></i> No posted movements for these filters.</div>@endforelse</div>
<div class="gl-table-heading"><div><h3>Transaction register</h3><p>Individual journal lines, organized by posting date</p></div><span class="gl-table-hint"><i class="fa-solid fa-arrows-left-right"></i> Scroll horizontally for all columns</span></div>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Journal / Ref</th><th>Ledger Account</th><th>Details</th><th>Currency</th><th class="gl-num">Debit</th><th class="gl-num">Credit</th><th class="gl-num">Running Balance</th></tr></thead><tbody>@forelse($rows as $line)<tr class="gl-transaction-row"><td class="gl-date">{{ \Illuminate\Support\Carbon::parse($line->entry_date)->format('d M Y') }}</td><td><a class="gl-link gl-journal-pill" href="{{ route('finance.journals.index') }}"><i class="fa-solid fa-book"></i> {{ $line->journal_number }}</a></td><td class="gl-account"><span class="gl-account-code">{{ $accounts->firstWhere('id',(int)$line->ledger_account_id)?->code }}</span><span class="gl-account-name">{{ $accounts->firstWhere('id',(int)$line->ledger_account_id)?->name }}</span></td><td class="gl-memo">{{ $line->memo ?: $line->journal_description }}</td><td><span class="gl-currency-chip">{{ $currencies->firstWhere('id',(int)$line->currency_id)?->code }}</span></td><td class="gl-num">@if((float)$line->debit!=0)<x-finance-amount :amount="$line->debit" direction="in" />@else<span class="gl-zero">—</span>@endif</td><td class="gl-num">@if((float)$line->credit!=0)<x-finance-amount :amount="$line->credit" direction="out" />@else<span class="gl-zero">—</span>@endif</td><td class="gl-num gl-running"><strong><x-finance-amount :amount="$balances[$line->id]" :direction="(float) str_replace(',', '', $balances[$line->id]) > 0 ? 'in' : ((float) str_replace(',', '', $balances[$line->id]) < 0 ? 'out' : 'neutral')" /></strong></td></tr>@empty<tr><td colspan="8" class="gl-muted"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-book-open" aria-hidden="true"></i></div><strong>No ledger activity found</strong><p>Try changing the filters, or post a journal to generate ledger entries.</p></div></td></tr>@endforelse</tbody></table></div><div class="gl-pagination">{{ $rows->onEachSide(1)->links('vendor.pagination.kanozay') }}</div></section>
@endsection
