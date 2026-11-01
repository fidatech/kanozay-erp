@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px"><a href="{{ route('production.orders.index') }}">Back to production orders</a><h2>Production plan {{ $order->number }}</h2>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
<p>Recipe: <a href="{{ route('production.recipes.show', $order->recipe) }}">{{ $order->recipe->name }}</a> · {{ $order->package_count }} packages · {{ $order->package_count * $order->recipe->package_weight_kg }} kg planned output</p>
<p><strong>Material requirements (saved when the plan was created)</strong></p>
<div style="overflow-x:auto"><table style="width:100%"><thead><tr><th>Material</th><th>Required quantity</th><th>Unit</th><th>Packaging</th><th>Inventory availability</th></tr></thead><tbody>
@forelse($materials as $material)<tr><td>{{ $material['name'] }}</td><td>{{ $material['quantity'] }}</td><td>{{ $material['unit'] }}</td><td>{{ $material['packaging'] ? 'Yes' : 'No' }}</td><td>Not yet connected</td></tr>@empty<tr><td colspan="5">No saved material requirements are available for this older plan. Do not use its recipe for purchasing or production without reviewing and recreating the plan.</td></tr>@endforelse
</tbody></table></div><p>Creating this plan does not reserve raw materials, start manufacturing, or post inventory or finance transactions.</p></div>
@endsection
