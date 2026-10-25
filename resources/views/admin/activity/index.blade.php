@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Audit Activity</h1><p>Recorded administrative changes to users and access roles.</p></div></div>
<section class="panel"><h2>Recent Events</h2><div class="table-wrap"><table><thead><tr><th>Date & Time</th><th>Actor</th><th>Action</th><th>Details</th></tr></thead><tbody>
@forelse($activities as $activity)<tr><td>{{ $activity->created_at->timezone('Asia/Kabul')->format('d M Y, H:i') }}</td><td>{{ $activity->actor?->name ?? 'Former user / System' }}</td><td><span class="permission-chip">{{ $activity->action }}</span></td><td>{{ $activity->description }}</td></tr>@empty<tr><td colspan="4" class="empty">No administrative events recorded yet.</td></tr>@endforelse
</tbody></table></div>{{ $activities->links() }}</section>
@endsection
