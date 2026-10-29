@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px"><h2>Kanozay Workbook Preview</h2><p>No records have been imported.</p>
@foreach($recipes as $recipe)
<h3>{{ $recipe['name'] }} — {{ $recipe['weight'] }} kg</h3><p>{{ $recipe['sheet'] }}</p>
@if($recipe['warnings'])<div role="alert"><ul>@foreach($recipe['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif
<table style="width:100%"><thead><tr><th>Material</th><th>Unit</th><th>Quantity</th><th>Unit cost</th><th>Source</th></tr></thead><tbody>
@foreach($recipe['lines'] as $line)<tr><td>{{ $line['material'] }}</td><td>{{ $line['unit'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['unit_cost'] }}</td><td>{{ $line['cell'] }}</td></tr>@endforeach
</tbody></table>
@endforeach
@if(collect($recipes)->every(fn ($recipe) => empty($recipe['warnings'])))
<form method="POST" action="{{ route('production.recipes.kanozay-confirm') }}">@csrf<button type="submit" class="btn">Confirm and Save Draft Recipes</button></form>
@else
<p role="alert">Confirmation is disabled until every discrepancy is resolved in the source workbook.</p>
@endif
<a href="{{ route('production.recipes.index') }}">Back to recipes</a></div>
@endsection
