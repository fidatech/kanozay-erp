@extends('layouts.kanozay')
@section('content')
<style>.admin-landing{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:1120px}.admin-link-card{display:flex;gap:15px;align-items:flex-start;padding:23px;border:1px solid #e3eaf5;border-radius:10px;background:white;transition:box-shadow .2s,border-color .2s}.admin-link-card:hover{border-color:#a9cdfb;box-shadow:0 8px 22px #123e7612}.admin-link-card>i{display:grid;place-items:center;width:48px;height:48px;flex:0 0 48px;border-radius:10px;background:#eaf4ff;color:#0868ef;font-size:20px}.admin-link-card h2{font-size:15px;margin:3px 0 8px}.admin-link-card p{color:#667b9e;line-height:1.5;margin:0}.admin-intro{margin-bottom:22px;color:#6077a1;font-size:13px}</style>
<div class="heading"><div><h1>Administration</h1><p>Manage access, permissions and system administration.</p></div></div>
<p class="admin-intro">Choose an administration area below or use the administration sidebar.</p>
<div class="admin-landing">
@can('users.view')<a class="admin-link-card" href="{{ route('admin.users.index') }}"><i class="fa-solid fa-users"></i><div><h2>User Management</h2><p>Create employee accounts, manage profiles and assign access roles.</p></div></a>@endcan
@can('roles.view')<a class="admin-link-card" href="{{ route('admin.roles.index') }}"><i class="fa-solid fa-shield-halved"></i><div><h2>Roles & Permissions</h2><p>Configure roles and control permissions for each ERP module.</p></div></a>@endcan
@can('administration.view')<a class="admin-link-card" href="{{ route('admin.activity.index') }}"><i class="fa-solid fa-clock-rotate-left"></i><div><h2>Audit Activity</h2><p>Review recorded user and role administration changes.</p></div></a>@endcan
</div>
@endsection
