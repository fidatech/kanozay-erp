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
<a href="{{ route('production.recipes.index') }}">Back to recipes</a></div>
@endsection
