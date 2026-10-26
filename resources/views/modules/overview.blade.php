@extends('layouts.kanozay')
@section('content')
<style>
.module-overview{--ov-border:#dce7f3;--ov-ink:#17385e;color:var(--ov-ink)}
.module-overview .overview-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:24px 27px;background:linear-gradient(110deg,#123761,#236ca6);color:#fff;border-radius:13px;margin-bottom:17px;flex-wrap:wrap}
.module-overview .overview-hero h1{font-size:23px;margin:0 0 8px}.module-overview .overview-hero p{margin:0;color:#d9eafa;font-size:13px}
.module-overview .overview-hero>i{font-size:30px;color:#d2e9ff}
.module-overview .overview-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;margin-bottom:17px}
.module-overview .overview-stat{padding:17px 19px;border:1px solid var(--ov-border);border-radius:11px;background:#fff}
.module-overview .overview-stat small{display:block;color:#687f99;font-size:12px;margin-bottom:9px}.module-overview .overview-stat strong{font-size:24px;font-weight:750}
.module-overview .overview-stat .stat-caption{display:block;font-size:11px;color:#71869c;margin-top:7px}
.module-overview .overview-section{display:flex;align-items:center;justify-content:space-between;margin:20px 0 11px;gap:10px}.module-overview .overview-section h2{font-size:15px;margin:0}
.module-overview .overview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:12px}
.module-overview .overview-link{display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid var(--ov-border);border-radius:11px;text-decoration:none;transition:transform .15s,box-shadow .15s}
.module-overview .overview-link:hover{transform:translateY(-2px);box-shadow:0 8px 18px #123f7220}
.module-overview .overview-link i{width:39px;height:39px;display:grid;place-items:center;background:#eaf3fc;color:#2166a0;border-radius:9px;font-size:16px;flex-shrink:0}
.module-overview .overview-link strong{display:block;font-size:13px;color:#17385e}.module-overview .overview-link small{display:block;color:#7185a0;margin-top:5px;font-size:11px}
.module-overview .overview-panel{margin-top:17px;background:#fff;border:1px solid var(--ov-border);border-radius:12px;overflow:hidden}
.module-overview .overview-panel-header{padding:15px 18px;border-bottom:1px solid var(--ov-border);display:flex;justify-content:space-between;gap:12px;align-items:center}
.module-overview .overview-panel-header h2{font-size:14px;margin:0}.module-overview .overview-panel-header small{color:#71869c}
.module-overview .overview-activity{padding:13px 18px;display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f8;font-size:12px}
.module-overview .overview-activity:last-child{border-bottom:0}.module-overview .overview-activity time{white-space:nowrap;color:#7a8da2}
.module-overview .overview-empty{padding:22px;color:#71869c;font-size:12px}
.module-overview .overview-more{border:1px solid var(--ov-border);border-radius:12px;background:#fff;margin-top:16px;padding:0 16px}
.module-overview .overview-more summary{cursor:pointer;padding:15px 0;font-size:13px;font-weight:700}.module-overview .overview-more .overview-grid{padding:0 0 17px}
.module-overview .overview-note{font-size:11px;color:#667e99;margin-top:14px;line-height:1.6}
@media(max-width:700px){.module-overview .overview-stats{grid-template-columns:1fr}.module-overview .overview-hero{padding:20px}.module-overview .overview-grid{grid-template-columns:1fr}}
</style>
<div class="module-overview">
 <div class="overview-hero"><div><h1>{{ $moduleData['label'] }}</h1><p>Overview, daily work, and important supporting operations in one place.</p></div><i class="fa-solid fa-{{ $moduleData['icon'] }}" aria-hidden="true"></i></div>
 @php($daily=array_values(array_filter(config('erp_navigation.'.$module,[]),fn($slug)=>isset($moduleData['pages'][$slug]) && $slug!=='overview')))
 @php($support=array_diff_key($moduleData['pages'],array_flip($daily),['overview'=>true]))
 <div class="overview-stats">
  <div class="overview-stat"><small>Daily workflows</small><strong>{{ count($daily) }}</strong><span class="stat-caption">Quick access to essential pages</span></div>
  <div class="overview-stat"><small>Supporting workflows</small><strong>{{ count($support) }}</strong><span class="stat-caption">Available without crowding the sidebar</span></div>
  <div class="overview-stat"><small>Module reporting</small><strong style="font-size:16px">Not available yet</strong><span class="stat-caption">KPIs will use actual operational records</span></div>
 </div>
 <div class="overview-section"><h2>Daily operations</h2><small>Choose a workspace</small></div>
 <div class="overview-grid">@foreach($daily as $slug)<a class="overview-link" href="{{ route('workspace.page',[$module,$slug]) }}"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span><strong>{{ $moduleData['pages'][$slug] }}</strong><small>Open workspace</small></span></a>@endforeach</div>
 @if(count($support))<details class="overview-more" open><summary>Additional workflows ({{ count($support) }})</summary><div class="overview-grid">@foreach($support as $slug=>$label)<a class="overview-link" href="{{ route('workspace.page',[$module,$slug]) }}"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><span><strong>{{ $label }}</strong><small>Related operation</small></span></a>@endforeach</div></details>@endif
 <div class="overview-panel"><div class="overview-panel-header"><h2>Recent system activity</h2><small>Latest recorded events across the ERP</small></div>
 @forelse($recentActivity as $event)<div class="overview-activity"><span>{{ $event->description }}</span><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->diffForHumans() }}</time></div>@empty<div class="overview-empty">No recorded activity yet.</div>@endforelse
 </div>
 <p class="overview-note">Operational KPI figures are not shown until their source workflows are implemented. Recent activity is system-wide, not a module-specific transaction report.</p>
</div>
@endsection
