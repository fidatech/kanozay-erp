@extends('layouts.kanozay')
@section('content')
<div style="max-width:1500px;margin:0 auto">
 <div class="card" style="padding:22px;margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><div><h2 style="margin:0">Production Recipes</h2><p style="margin:6px 0;color:#64748b">Liquid and powder blending · draft formulas only</p></div>@can('administration.update')<button class="btn" type="button" onclick="document.getElementById('recipe-dialog').showModal()">+ Add Recipe</button>@endcan</div>
  @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
  @if($errors->any())<div role="alert" style="color:#b42318">{{ $errors->first() }}</div>@endif
  @can('administration.update')<form method="POST" action="{{ route('production.recipes.import-preview') }}" enctype="multipart/form-data" style="margin:14px 0">@csrf<label>Preview recipe CSV or Excel (no import)<input type="file" name="file" accept=".csv,.xlsx,.xls" required></label><button type="submit" class="btn">Validate File</button><p style="font-size:12px">Columns: recipe_name, method, package_weight_kg, package_unit, material_name, unit, quantity_per_package, unit_cost, source_sheet, source_cell. Prepare a copy of the workbook with these exact columns; the original Excel file is not modified.</p></form>@endcan
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th>Recipe</th><th>Method</th><th>Package</th><th>Ingredients</th><th>Source</th><th>Status</th></tr></thead><tbody>@forelse($recipes as $recipe)<tr><td>{{ $recipe->name }}</td><td>{{ str_replace('_',' ',ucfirst($recipe->method)) }}</td><td>{{ $recipe->package_weight_kg }} kg / {{ $recipe->package_unit }}</td><td>{{ $recipe->lines_count }}</td><td>{{ $recipe->source_sheet ?? 'Manual entry' }}</td><td>{{ ucfirst($recipe->status) }}</td></tr>@empty<tr><td colspan="6">No recipes entered yet. Importing company records requires validation first.</td></tr>@endforelse</tbody></table></div>
  {{ $recipes->links() }}
 </div>
</div>
@can('administration.update')
<dialog id="recipe-dialog" style="width:min(850px,95vw);border:0;border-radius:14px;padding:24px"><form method="POST" action="{{ route('production.recipes.store') }}">@csrf
<h2>New Draft Recipe</h2><p>Ingredient quantities are per package. Recipes do not post inventory movements.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
<label>Recipe name<input required name="name" maxlength="180"></label><label>Product code<input name="product_code" maxlength="80"></label>
<label>Production method<select name="method" required><option value="liquid_blending">Liquid blending</option><option value="powder_blending">Powder blending</option></select></label>
<label>Package weight (kg)<input name="package_weight_kg" type="number" step="0.0001" min="0.0001" required></label><label>Package unit<input name="package_unit" required placeholder="can or bag"></label>
<label>Currency code<input name="cost_currency_code" maxlength="3" placeholder="USD"></label><label>Source worksheet<input name="source_sheet" maxlength="120"></label><label>Source file<input name="source_reference" maxlength="180"></label>
</div>
<h3>Ingredients per package</h3><div style="overflow-x:auto"><table style="width:100%"><thead><tr><th>Material</th><th>Unit</th><th>Quantity</th><th>Unit cost</th><th></th></tr></thead><tbody id="recipe-lines"></tbody></table></div>
<button type="button" class="btn" id="recipe-add-line">+ Add Ingredient</button>
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px"><button type="button" onclick="document.getElementById('recipe-dialog').close()">Cancel</button><button type="submit" class="btn">Save Draft</button></div>
</form></dialog>
<script>
(()=>{const body=document.getElementById('recipe-lines');let next=0;const add=()=>{const i=next++;const tr=document.createElement('tr');tr.innerHTML=`<td><input required maxlength="180" name="lines[${i}][material_name]"></td><td><input required name="lines[${i}][unit]" maxlength="30" placeholder="kg"></td><td><input required type="number" min="0.000001" step="0.000001" name="lines[${i}][quantity_per_package]"></td><td><input type="number" min="0" step="0.000001" name="lines[${i}][unit_cost]"></td><td><button type="button" class="recipe-remove">Remove</button></td>`;body.append(tr)};document.getElementById('recipe-add-line').addEventListener('click',add);body.addEventListener('click',e=>{if(e.target.closest('.recipe-remove')&&body.children.length>1)e.target.closest('tr').remove()});add()})();
</script>
@endcan
@endsection
