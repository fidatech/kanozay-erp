@extends('layouts.kanozay')
@section('content')
<div style="max-width:1250px;margin:auto"><div class="card" style="padding:24px">
<div style="display:flex;justify-content:space-between;align-items:center;gap:16px"><div><a href="{{ route('production.recipes.index') }}">Back to recipes</a><h2>{{ $recipe->name }}</h2><p>{{ str_replace('_', ' ', ucfirst($recipe->method)) }} · {{ $recipe->package_weight_kg }} kg / {{ $recipe->package_unit }} · {{ ucfirst($recipe->status) }}</p></div>
@if($recipe->status === 'draft')
@can('administration.update')<form method="POST" action="{{ route('production.recipes.approve', $recipe) }}" onsubmit="return confirm('Approve this recipe for future production use?')">@csrf<button class="btn" type="submit">Approve Recipe</button></form>
@endcan
@endif
</div>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" style="color:#b42318">{{ $errors->first() }}</p>@endif
<p>Product code: {{ $recipe->product_code ?: 'Not specified' }} · Source: {{ $recipe->source_sheet ?: 'Manual' }} · Reference: {{ $recipe->source_reference ?: 'None' }}</p>
@if($recipe->approved_at)<p>Approved by {{ $recipe->approver?->name ?? 'Former user' }} on {{ $recipe->approved_at->format('Y-m-d H:i') }}</p>@endif
<div style="overflow-x:auto"><table style="width:100%"><thead><tr><th>#</th><th>Material</th><th>Unit</th><th>Quantity per package</th><th>Unit cost</th><th>Packaging</th><th>Source cell</th></tr></thead><tbody>
@foreach($recipe->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->material_name }}</td><td>{{ $line->unit }}</td><td>{{ $line->quantity_per_package }}</td><td>{{ $line->unit_cost ?? '—' }}</td><td>{{ $line->is_packaging ? 'Yes' : 'No' }}</td><td>{{ $line->source_cell ?? '—' }}</td></tr>@endforeach
</tbody></table></div><p>Approval checks positive quantities and total non-packaging kg against package weight. No stock or ledger movements occur here.</p>
</div></div>
@endsection
