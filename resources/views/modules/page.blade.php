@extends('layouts.kanozay')
@section('content')
<div class="heading"><div><h1><i class="fa-solid fa-{{ $moduleData['icon'] }}"></i> {{ $pageTitle }}</h1><p>{{ $moduleData['label'] }} / {{ $pageTitle }}</p></div><span class="status" style="background:#fff4df;color:#97611b">Page structure ready · Workflow pending</span></div>
<section class="panel"><h2>{{ $pageTitle }}</h2><p class="muted">This page is part of the Kanozay ERP module structure. Data entry, approvals, calculations and posting workflows will be implemented and tested in Stage 2. No transactions can be created from this placeholder.</p><div class="table-wrap"><table><thead><tr><th>Module</th><th>Page</th><th>Implementation Status</th></tr></thead><tbody><tr><td>{{ $moduleData['label'] }}</td><td>{{ $pageTitle }}</td><td>Structure ready — functionality pending</td></tr></tbody></table></div></section>
@endsection
