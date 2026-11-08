@extends('layouts.kanozay')
@section('content')
@php($t=fn(string $key)=>\App\Support\ReportingLabels::text($key))
<style>
.pwr-page{display:grid;gap:15px}
.pwr-head{display:flex;gap:12px;justify-content:space-between;align-items:start;flex-wrap:wrap}
.pwr-panel{border:1px solid #dce6f0;border-radius:12px;background:#fff;padding:18px}
.pwr-panel p{line-height:1.7}
.pwr-form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}
.pwr-form label{display:grid;gap:5px;font-size:12px;font-weight:700}
.pwr-form input,.pwr-form select{padding:10px;border:1px solid #d8e5f2;border-radius:8px;min-width:145px}
.pwr-scroll{overflow-x:auto}.pwr-scroll table{min-width:1150px}
.pwr-scroll th,.pwr-scroll td{padding:11px;text-align:start;vertical-align:top}
.pwr-warning{background:#fff7e9;color:#7c5221;padding:13px;border:1px solid #efd6aa;border-radius:8px}
.pwr-alert{color:#a53d32;font-weight:650}
.pwr-normal{color:#177447;font-weight:650}
</style>
<div class="pwr-page">
<div class="pwr-head">
<div><h1>{{ $t('production_waste_report') }}</h1>
<p class="muted">{{ $t('production_waste_description') }}</p></div>
<a class="btn" href="{{ route('reports.production-waste.index',array_merge(request()->query(),['export'=>'csv'])) }}">{{ $t('export_csv') }}</a>
</div>
<div class="pwr-warning">
<strong>{{ $t('production_waste_accounting_warning') }}</strong>
<p style="margin:7px 0 0">Source-recorded material was already physically issued. No row in this report is a second stock deduction or an automatic waste expense. An abnormal loss requires separate verified FIFO lot-cost attribution and independently approved accounting treatment. Pending rework remains blocked from saleable finished-stock capitalization.</p>
</div>
<section class="pwr-panel">
<form method="GET" action="{{ route('reports.production-waste.index') }}" class="pwr-form">
<label>{{ $t('from') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ $t('to') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ $t('production_waste_treatment') }}<select name="treatment">
<option value="">{{ $t('production_waste_all') }}</option>
@foreach(['pending'=>'production_waste_pending','normal_process_loss'=>'production_waste_normal','abnormal_loss'=>'production_waste_abnormal','rework_required'=>'production_waste_rework'] as $value=>$label)
<option value="{{ $value }}" @selected(($filters['treatment']??'')===$value)>{{ $t($label) }}</option>
@endforeach
</select></label>
<button type="submit" class="btn">{{ $t('apply_filters') }}</button>
<a href="{{ route('reports.production-waste.index') }}" class="btn btn-light">{{ $t('reset') }}</a>
</form>
<p class="muted">{{ $rows->total() }} matching source waste records. Currency and cost were intentionally not inferred from material quantity or an unverified blend of FIFO lots.</p>
</section>
<section class="pwr-panel">
<div class="pwr-scroll"><table>
<thead><tr>
<th>{{ $t('production_order') }}</th><th>{{ $t('production_batch') }}</th>
<th>{{ $t('material') }}</th><th>{{ $t('waste_quantity') }}</th>
<th>{{ $t('reason') }}</th><th>{{ $t('recorded_at') }}</th>
<th>{{ $t('production_waste_treatment') }}</th><th>{{ $t('approval_reference') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td><a href="{{ route('production.orders.show',$row->production_order_id) }}">{{ $row->order_number }}</a>
<div class="muted">{{ $row->order_status }}</div></td>
<td>{{ $row->batch_number??'Not yet submitted for QC' }}
<div class="muted">{{ $row->batch_status }}</div></td>
<td>{{ $row->material_name }}</td>
<td>{{ \App\Support\ReportingLabels::decimal($row->quantity) }} {{ $row->unit }}</td>
<td>{{ $row->reason }}</td><td>{{ $row->created_at }}</td>
<td>
@if($row->treatment==='normal_process_loss')
<span class="pwr-normal">{{ $t('production_waste_normal') }}</span>
@elseif($row->treatment==='abnormal_loss')
<span class="pwr-alert">{{ $t('production_waste_abnormal') }}</span>
@elseif($row->treatment==='rework_required')
<span class="pwr-alert">{{ $t('production_waste_rework') }}</span>
@else
<span class="pwr-alert">{{ $t('production_waste_pending') }}</span>
@endif
@if($row->review_notes)<div class="muted">{{ $row->review_notes }}</div>@endif
</td>
<td>{{ $row->approval_reference??'—' }}
@if($row->reviewed_by)<div class="muted">Reviewer #{{ $row->reviewed_by }}</div>@endif</td>
</tr>
@empty<tr><td colspan="8">{{ $t('no_filter_matches') }}</td></tr>@endforelse
</tbody></table></div>
{{ $rows->links() }}
</section>
</div>
@endsection
