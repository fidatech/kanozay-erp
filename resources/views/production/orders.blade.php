@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><div><h2>Production Orders</h2><p>Track plans, material issues, laboratory acceptance and finished goods release.</p></div>@can('inventory.view')<a class="btn" href="{{ route('production.finished-goods.index') }}">Finished goods</a>@endcan</div>
@if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
@canany(['administration.update', 'manufacturing.create'])<form method="POST" action="{{ route('production.orders.store') }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:18px 0">@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Approved recipe<select name="production_recipe_id" required><option value="">Select approved recipe</option>@foreach($recipes as $recipe)<option value="{{ $recipe->id }}">{{ $recipe->name }} — {{ $recipe->package_weight_kg }} kg</option>@endforeach</select></label>
<label>Packages<input type="number" name="package_count" min="1" max="100000" required></label><label>Notes<input name="notes" maxlength="2000"></label><button class="btn" type="submit">Create Production Plan</button></form>@endcanany
<div style="overflow-x:auto"><table style="width:100%"><thead><tr><th>Order</th><th>Recipe</th><th>Packages</th><th>Status</th><th>Date</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a href="{{ route('production.orders.show', $order) }}">{{ $order->number }}</a></td><td>{{ $order->recipe?->name }}</td><td>{{ $order->package_count }}</td><td>{{ ucfirst($order->status) }}</td><td>{{ $order->created_at->format('Y-m-d') }}</td></tr>@empty<tr><td colspan="5">No production plans yet.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}</div>
@endsection
