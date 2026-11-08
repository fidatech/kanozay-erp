@extends('layouts.kanozay')
@section('content')
@php($t=fn(string $key)=>\App\Support\ReportingLabels::text($key))
<style>
.k-dashboard .k-dashboard-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:18px}
.k-dashboard .k-dashboard-hero h1{margin:0 0 6px;color:#17395f;font-size:24px}
.k-dashboard .k-dashboard-hero p{margin:0;color:#71859c;font-size:13px}
.k-dashboard .k-dashboard-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.k-dashboard .k-dashboard-filters label{display:grid;gap:5px;color:#355573;font-size:11px;font-weight:700}
.k-dashboard .k-dashboard-filters input{height:38px;border:1px solid #d9e4ef;border-radius:8px;padding:7px 10px}
.k-dashboard .k-dashboard-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:12px;margin-bottom:18px}
.k-dashboard .k-dashboard-card{display:flex;align-items:center;gap:12px;min-height:86px;padding:15px;border:1px solid #dce7f3;border-radius:11px;background:#fff;text-decoration:none;color:inherit}
.k-dashboard .k-dashboard-card:hover{border-color:#aac9e5;box-shadow:0 4px 14px #193b5b0a}
.k-dashboard .k-dashboard-icon{display:grid;place-items:center;flex:0 0 40px;height:40px;border-radius:10px;background:#edf5fd;color:#2166a0}
.k-dashboard .k-dashboard-value{display:block;color:#17385e;font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}
.k-dashboard .k-dashboard-label{display:block;color:#355573;font-size:12px;font-weight:700}
.k-dashboard .k-dashboard-hint{display:block;margin-top:3px;color:#8092a7;font-size:10px}
.k-dashboard .k-dashboard-table{padding:0;overflow:hidden}
.k-dashboard .k-dashboard-table-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid #e6edf5}
.k-dashboard .k-dashboard-table-head h2{margin:0;color:#1e4167;font-size:15px}
.k-dashboard .k-dashboard-table .table-wrap{margin:0!important;width:100%!important}
@media(max-width:600px){.k-dashboard .k-dashboard-hero h1{font-size:21px}.k-dashboard .k-dashboard-table .table-wrap{overflow-x:auto}}
</style>
<div class="k-dashboard">
    <div class="k-dashboard-hero">
        <div><h1>{{ $t('good_'.(now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening'))) }}, {{ explode(' ', auth()->user()->name)[0] }}.</h1><p>{{ $t('dashboard_operations') }}: {{ $from }} — {{ $to }}.</p></div>
        <form class="k-dashboard-filters" method="GET" action="{{ route('dashboard') }}">
            <label>{{ $t('from') }}<input type="date" name="from" value="{{ $from }}" required></label>
            <label>{{ $t('to') }}<input type="date" name="to" value="{{ $to }}" required></label>
            <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> {{ $t('apply_filters') }}</button>
        </form>
    </div>

    @if(count($cards))
        <div class="k-dashboard-cards">
            @foreach($cards as $card)
                <a class="k-dashboard-card" href="{{ $card['url'] }}"><span class="k-dashboard-icon"><i class="fa-solid {{ $card['icon'] }}"></i></span><span><span class="k-dashboard-label">{{ \App\Support\ReportingLabels::dashboardCard($card['label']) }}</span><strong class="k-dashboard-value">{{ is_numeric($card['value']) ? number_format((int)$card['value']) : $card['value'] }}</strong><small class="k-dashboard-hint">{{ \App\Support\ReportingLabels::dashboardCard($card['hint']) }}</small></span></a>
            @endforeach
        </div>
    @else
        <section class="panel"><div class="k-empty-state"><div class="k-empty-icon"><i class="fa-solid fa-gauge-high"></i></div><strong>{{ $t('no_permission_cards') }}</strong><p>Ask an administrator to assign access to the modules you use.</p></div></section>
    @endif

    <section class="panel k-dashboard-table">
        <div class="k-dashboard-table-head"><div><h2>{{ $t('recent_activity') }}</h2><small>{{ $t('recent_activity_note') }}</small></div></div>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>{{ $t('party_description') }}</th><th>{{ $t('status_currency') }}</th><th>{{ $t('view_record') }}</th></tr></thead><tbody>
            @forelse($recent as $row)
                <tr><td>{{ $row['date'] }}</td><td>{{ \App\Support\ReportingLabels::dashboardCard($row['type']) }}</td><td><strong>{{ $row['reference'] }}</strong></td><td>{{ $row['party'] }}</td><td>{{ $row['status'] }}</td><td><a class="btn btn-light" href="{{ $row['url'] }}">{{ $t('view_record') }}</a></td></tr>
            @empty
                <tr><td colspan="6"><div class="k-empty-state" role="status"><div class="k-empty-icon"><i class="fa-solid fa-receipt"></i></div><strong>{{ $t('no_recent_activity') }}</strong><p>{{ $t('accessible_records') }}</p></div></td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
