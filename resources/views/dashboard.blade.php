@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1>Executive Overview</h1><p class="muted">Welcome back, {{ auth()->user()->name }}. Your Kanozay workspace is ready.</p></div><span class="pill">Single factory · Multiple warehouses</span></div>
<div class="cards">
<div class="card"><span class="muted">Production</span><strong>—</strong><span class="subtle">Awaiting manufacturing module</span></div>
<div class="card"><span class="muted">Inventory</span><strong>—</strong><span class="subtle">Awaiting stock module</span></div>
<div class="card"><span class="muted">Sales</span><strong>—</strong><span class="subtle">Awaiting sales module</span></div>
<div class="card"><span class="muted">Financial position</span><strong>—</strong><span class="subtle">Awaiting accounting module</span></div>
</div>
<div class="card" style="margin-top:22px"><h2>Administration</h2><p class="muted">Manage employee access and role-based permissions before configuring operational modules.</p>@can('users.view')<a class="btn" href="{{ route('admin.users.index') }}">Manage users</a>@endcan @can('roles.view')<a class="btn" href="{{ route('admin.roles.index') }}">Roles & permissions</a>@endcan</div>
@endsection