@extends('layouts.kanozay')
@section('content')
<div class="card" style="padding:24px"><h2>Recipe Import Preview</h2><p><strong>{{ $rowCount }}</strong> valid ingredient rows detected. Nothing has been imported or posted.</p>
@if($errors)<h3 style="color:#9a3412">Warnings and validation errors ({{ count($errors) }})</h3><ul>@foreach($errors as $error)<li>{{ $error }}</li>@endforeach</ul>@else<p>All rows passed basic validation. Review source formulas and units before approving any import.</p>@endif
<table style="width:100%"><thead><tr><th>Recipe</th><th>Method</th><th>Ingredient lines</th><th>Sum of kg lines</th><th>Package kg</th></tr></thead><tbody>@foreach($summaries as $item)<tr><td>{{ $item['name'] }}</td><td>{{ $item['method'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['weight'] }}</td><td>{{ $item['package'] }}</td></tr>@endforeach</tbody></table>
<p><a href="{{ route('production.recipes.index') }}">Back to Recipes</a></p></div>
@endsection
