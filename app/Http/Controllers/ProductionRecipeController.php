<?php

namespace App\Http\Controllers;

use App\Models\ProductionRecipe;
use App\Models\ActivityLog;
use App\Support\LocalizedDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionRecipeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view', 'manufacturing.view']), 403);
        return view('production.recipes', ['recipes' => ProductionRecipe::with('approver')->withCount('lines')->latest('id')->paginate(20)]);
    }

    private function normalize(Request $request): void
    {
        $lines=$request->input('lines');
        if(is_array($lines)){
            foreach($lines as &$line){
                if(!is_array($line))continue;
                foreach(['quantity_per_package','unit_cost'] as $name){
                    if(array_key_exists($name,$line)){
                        $line[$name]=LocalizedDecimal::normalize($line[$name]);
                    }
                }
            }
            unset($line);
        }
        $request->merge([
            'package_weight_kg'=>LocalizedDecimal::normalize($request->input('package_weight_kg')),
            'lines'=>$lines,
        ]);
    }

    private function validateRecipe(Request $request): array
    {
        return $request->validate([
            'name'=>['required','string','max:180'],
            'product_code'=>['nullable','string','max:80'],
            'method'=>['required',Rule::in(['liquid_blending','powder_blending'])],
            'package_weight_kg'=>['required','numeric','gt:0','decimal:0,4','max:99999999.9999'],
            'package_unit'=>['required','string','max:30'],
            'cost_currency_code'=>['nullable','string','size:3'],
            'source_sheet'=>['nullable','string','max:120'],
            'source_reference'=>['nullable','string','max:180'],
            'notes'=>['nullable','string','max:3000'],
            'lines'=>['required','array','min:1','max:100'],
            'lines.*.material_name'=>['required','string','max:180'],
            'lines.*.unit'=>['required','string','max:30'],
            'lines.*.quantity_per_package'=>['required','numeric','gt:0','decimal:0,6','max:999999999999.999999'],
            'lines.*.unit_cost'=>['nullable','numeric','gte:0','decimal:0,6','max:999999999999.999999'],
            'lines.*.is_packaging'=>['nullable','boolean'],
            'lines.*.source_cell'=>['nullable','string','max:20'],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.update', 'manufacturing.update']), 403);
        $this->normalize($request);
        $data=$this->validateRecipe($request);
        DB::transaction(function()use($data,$request){
            $recipe=ProductionRecipe::create(array_merge(collect($data)->except('lines')->all(),[
                'status'=>'draft','created_by'=>$request->user()->id,
            ]));
            foreach(array_values($data['lines']) as $index=>$line){
                $recipe->lines()->create(array_merge($line,[
                    'line_number'=>$index+1,
                    'is_packaging'=>(bool)($line['is_packaging']??false),
                ]));
            }
            ActivityLog::record('production.recipe.created','production_recipe',$recipe->id,
                'Draft formula created with '.count($data['lines']).' ingredient/packaging lines.');
        });
        return redirect()->route('production.recipes.index')
            ->with('success','Draft recipe saved. Review packaging and ingredient quantities before approval.');
    }

    public function updateDraft(Request $request, ProductionRecipe $recipe)
    {
        abort_unless($request->user()->canAny(['administration.update','manufacturing.update']),403);
        $this->normalize($request);
        $data=$this->validateRecipe($request);
        DB::transaction(function()use($recipe,$data){
            $locked=ProductionRecipe::whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='draft'){
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipe'=>'Only unapproved draft formulas can be edited.',
                ]);
            }
            $old=$locked->lines()->orderBy('line_number')->get()->map(fn($line)=>[
                'material_name'=>$line->material_name,'unit'=>$line->unit,
                'quantity_per_package'=>$line->quantity_per_package,
                'is_packaging'=>$line->is_packaging,
                'unit_cost'=>$line->unit_cost,
            ])->all();
            $locked->update(collect($data)->except('lines')->all());
            $locked->lines()->delete();
            foreach(array_values($data['lines']) as $index=>$line){
                $locked->lines()->create(array_merge($line,[
                    'line_number'=>$index+1,
                    'is_packaging'=>(bool)($line['is_packaging']??false),
                ]));
            }
            ActivityLog::record('production.recipe.draft_updated','production_recipe',$locked->id,
                'Draft production formula updated before approval.',
                ['previous_lines'=>$old,'new_lines'=>count($data['lines'])]);
        });
        return redirect()->route('production.recipes.show',$recipe)
            ->with('success','Draft recipe updated. No stock or production history was changed.');
    }

    public function show(Request $request, ProductionRecipe $recipe)
    {
        abort_unless($request->user()->canAny(['administration.view', 'manufacturing.view']), 403);
        $recipe->load(['lines', 'approver']);
        return view('production.recipe-show', compact('recipe'));
    }

    public function approve(Request $request, ProductionRecipe $recipe)
    {
        abort_unless($request->user()->canAny(['administration.update', 'manufacturing.update']), 403);
        $error = DB::transaction(function () use ($recipe, $request) {
            $recipe = ProductionRecipe::whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            if ($recipe->status !== 'draft') return 'Only draft recipes may be approved.';
            $lines = $recipe->lines()->get();
            if ($lines->isEmpty()) return 'Recipe has no ingredients.';
            if ($lines->contains(fn ($line) => ! BigDecimal::of((string) $line->quantity_per_package)->isGreaterThan(0)
                || ! trim($line->material_name) || ! trim($line->unit))) {
                return 'Recipe contains invalid ingredient quantities or missing details.';
            }
            $weight = $lines
                ->filter(fn ($line) => strtolower(trim($line->unit)) === 'kg' && ! $line->is_packaging)
                ->reduce(
                    fn (BigDecimal $total, $line) => $total->plus((string) $line->quantity_per_package),
                    BigDecimal::zero()
                );
            if ($weight->minus((string) $recipe->package_weight_kg)->abs()->isGreaterThan(BigDecimal::of('0.01'))) {
                return 'Ingredient weight differs from the package weight. Review the formula before approval.';
            }
            $recipe->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            return null;
        });
        if ($error) return back()->withErrors(['approval' => $error]);
        return redirect()->route('production.recipes.show', $recipe)->with('success', 'Recipe approved. No inventory or ledger entries were posted.');
    }

}
