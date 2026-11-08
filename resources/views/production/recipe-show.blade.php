@extends('layouts.kanozay')
@section('content')
<style>
.recipe-panel{padding:22px;background:#fff;border:1px solid #dbe6f0;border-radius:12px;margin:14px 0}
.recipe-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.recipe-fields label,.recipe-edit td label{display:grid;gap:6px;font-size:12px;color:#405b76;font-weight:600}
.recipe-fields input,.recipe-fields select,.recipe-edit input,.recipe-edit select{width:100%;box-sizing:border-box;border:1px solid #d5e2ee;border-radius:8px;padding:10px;min-width:0}
.recipe-edit table{min-width:850px;font-size:12px}
.recipe-edit th,.recipe-edit td{padding:10px}
.recipe-edit th{background:#eff5fb}
.recipe-hint{font-size:12px;color:#667d94;margin:10px 0;line-height:1.65}
</style>
<div class="heading">
 <div><h1>{{ $recipe->name }}</h1><p>Production recipe · {{ ucfirst($recipe->status) }} · {{ $recipe->package_weight_kg }} kg / {{ $recipe->package_unit }}</p></div>
 <a class="btn btn-light" href="{{ route('production.recipes.index') }}">All recipes</a>
</div>
@if(session('success'))<div class="panel" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="panel" role="alert" style="color:#b42318">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="recipe-panel">
<div style="display:flex;gap:12px;justify-content:space-between;align-items:start;flex-wrap:wrap">
 <div>
 <h2>{{ $recipe->name }}</h2>
 <p>{{ str_replace('_',' ',ucfirst($recipe->method)) }} · Product code: {{ $recipe->product_code?:'Not specified' }}
 · Source worksheet: {{ $recipe->source_sheet?:'Manual entry' }}</p>
 <p>Source reference: {{ $recipe->source_reference?:'Not specified' }} · Currency: {{ $recipe->cost_currency_code?:'Not specified' }}</p>
 @if($recipe->notes)<p>{{ $recipe->notes }}</p>@endif
 @if($recipe->approved_at)<p>Approved by {{ $recipe->approver?->name??'Former user' }} on {{ $recipe->approved_at->format('Y-m-d H:i') }}</p>@endif
 </div>
 @if($recipe->status==='draft')
 @canany(['administration.update','manufacturing.update'])
 <form method="POST" action="{{ route('production.recipes.approve',$recipe) }}" onsubmit="return confirm('Approve this reviewed formula for future production orders?')">
 @csrf<button type="submit" class="btn">Approve recipe</button></form>
 @endcanany
 @endif
</div>
<div class="table-wrap"><table style="min-width:750px">
<thead><tr><th>#</th><th>Material</th><th>Unit</th><th>Quantity per package</th><th>Estimated unit cost</th><th>Type</th><th>Source cell</th></tr></thead>
<tbody>
@foreach($recipe->lines as $line)
<tr>
<td>{{ $line->line_number }}</td><td>{{ $line->material_name }}</td><td>{{ $line->unit }}</td>
<td>{{ $line->quantity_per_package }}</td><td>{{ $line->unit_cost??'—' }}</td>
<td>{{ $line->is_packaging?'Packaging':'Ingredient' }}</td><td>{{ $line->source_cell??'—' }}</td>
</tr>
@endforeach
</tbody></table></div>
<p class="recipe-hint">Ingredient and packaging quantities are recorded per finished package. Approval verifies the non-packaging kilogram formula against declared net package weight. Actual inventory and cost postings happen only when materials are issued and a QC-approved batch is released.</p>
</section>
@if($recipe->status==='draft')
@canany(['administration.update','manufacturing.update'])
<section class="recipe-panel recipe-edit">
<h2>Edit draft formula</h2>
<p class="recipe-hint">Changes are allowed only while this recipe is a draft. Saving replaces its draft ingredient list in a single transaction. Approved recipes and previously created production-order snapshots remain unchanged.</p>
<form action="{{ route('production.recipes.update',$recipe) }}" method="POST" id="recipe-edit-form">
@csrf @method('PATCH')
<div class="recipe-fields">
<label>Recipe name<input name="name" maxlength="180" required value="{{ old('name',$recipe->name) }}"></label>
<label>Product SKU<input name="product_code" maxlength="80" value="{{ old('product_code',$recipe->product_code) }}"></label>
<label>Method<select name="method" required>
<option value="liquid_blending" @selected(old('method',$recipe->method)==='liquid_blending')>Liquid blending</option>
<option value="powder_blending" @selected(old('method',$recipe->method)==='powder_blending')>Powder blending</option>
</select></label>
<label>Net package weight (kg)<input name="package_weight_kg" type="text" inputmode="decimal" required value="{{ old('package_weight_kg',$recipe->package_weight_kg) }}"></label>
<label>Package unit<input name="package_unit" required maxlength="30" value="{{ old('package_unit',$recipe->package_unit) }}"></label>
<label>Cost currency code<input name="cost_currency_code" maxlength="3" value="{{ old('cost_currency_code',$recipe->cost_currency_code) }}"></label>
<label>Original worksheet<input name="source_sheet" maxlength="120" value="{{ old('source_sheet',$recipe->source_sheet) }}"></label>
<label>Source reference<input name="source_reference" maxlength="180" value="{{ old('source_reference',$recipe->source_reference) }}"></label>
<label style="grid-column:1/-1">Notes<input name="notes" maxlength="3000" value="{{ old('notes',$recipe->notes) }}"></label>
</div>
<h3>Ingredients and packaging per package</h3>
<div class="table-wrap"><table>
<thead><tr><th>Material</th><th>Unit</th><th>Quantity</th><th>Estimated unit cost</th><th>Packaging</th><th>Source cell</th><th>Action</th></tr></thead>
<tbody id="recipe-edit-lines">
@foreach($recipe->lines as $line)
<tr>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][material_name]" maxlength="180" required value="{{ $line->material_name }}"></td>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][unit]" maxlength="30" required value="{{ $line->unit }}"></td>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][quantity_per_package]" inputmode="decimal" required value="{{ $line->quantity_per_package }}"></td>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][unit_cost]" inputmode="decimal" value="{{ $line->unit_cost }}"></td>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][is_packaging]" type="hidden" value="0">
<input form="recipe-edit-form" name="lines[{{ $loop->index }}][is_packaging]" type="checkbox" value="1" @checked($line->is_packaging) style="width:auto"></td>
<td><input form="recipe-edit-form" name="lines[{{ $loop->index }}][source_cell]" maxlength="20" value="{{ $line->source_cell }}"></td>
<td><button class="btn btn-light recipe-remove-line" type="button">Remove</button></td>
</tr>
@endforeach
</tbody>
</table></div>
<template id="recipe-draft-line-template">
<tr>
<td><input form="recipe-edit-form" name="lines[__INDEX__][material_name]" maxlength="180" required></td>
<td><input form="recipe-edit-form" name="lines[__INDEX__][unit]" maxlength="30" required></td>
<td><input form="recipe-edit-form" name="lines[__INDEX__][quantity_per_package]" inputmode="decimal" required></td>
<td><input form="recipe-edit-form" name="lines[__INDEX__][unit_cost]" inputmode="decimal"></td>
<td><input form="recipe-edit-form" name="lines[__INDEX__][is_packaging]" type="hidden" value="0">
<input form="recipe-edit-form" name="lines[__INDEX__][is_packaging]" type="checkbox" value="1" style="width:auto"></td>
<td><input form="recipe-edit-form" name="lines[__INDEX__][source_cell]" maxlength="20"></td>
<td><button type="button" class="btn btn-light recipe-remove-line">Remove</button></td>
</tr>
</template>
<div style="display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap;margin-top:14px">
<button type="button" id="recipe-draft-add-line" class="btn btn-light">Add material line</button>
<button type="submit" class="btn">Save draft formula</button>
</div>
</form>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const body=document.getElementById('recipe-edit-lines');
 const template=document.getElementById('recipe-draft-line-template');
 let counter={{ $recipe->lines->count() }};
 document.getElementById('recipe-draft-add-line').addEventListener('click',()=>{
  if(body.children.length>=100)return;
  const row=template.innerHTML.replaceAll('__INDEX__',String(counter++));
  body.insertAdjacentHTML('beforeend',row);
 });
 body.addEventListener('click',event=>{
  if(event.target.closest('.recipe-remove-line') && body.children.length>1){
   event.target.closest('tr').remove();
  }
 });
});
</script>
</section>
@endcanany
@endif
@endsection
