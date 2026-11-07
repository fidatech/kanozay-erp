<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\InventoryMaterial;
use App\Models\ProcurementRequest;
use App\Models\TradeParty;
use App\Services\ProcurementRequestService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProcurementRequestController extends Controller
{
    private function guard(Request $request,bool $write=false):void
    {
        $permissions=$write?['inventory.create','purchases.create','administration.update']:
            ['inventory.view','purchases.view','administration.view'];
        abort_unless($request->user()->canAny($permissions),403);
    }

    public function index(Request $request,string $module,string $page)
    {
        $this->guard($request);
        $materials=InventoryMaterial::where('is_active',true)->orderBy('name')->get();
        $rules=DB::table('material_reorder_settings')->get()->keyBy('inventory_material_id');
        $suggestions=[];
        foreach($materials as $material){
            $rule=$rules->get($material->id);
            $available=BigDecimal::of((string)$material->available_quantity);
            $minimum=$rule?BigDecimal::of((string)$rule->minimum_quantity):null;
            $target=$rule?BigDecimal::of((string)$rule->target_quantity):null;
            $suggested=$target && $minimum && $available->isLessThanOrEqualTo($minimum)
                ? $target->minus($available):BigDecimal::zero();
            $suggestions[]=[
                'material'=>$material,'rule'=>$rule,
                'available'=>(string)$available->toScale(6),
                'suggested'=>(string)($suggested->isPositive()?$suggested:BigDecimal::zero())->toScale(6),
            ];
        }

        return view('procurement.requests',[
            'module'=>$module,'page'=>$page,
            'pageTitle'=>config('erp_pages.'.$module.'.pages.'.$page),
            'materials'=>$materials,'suggestions'=>$suggestions,
            'requests'=>ProcurementRequest::with(['lines.material','creator','approver','order'])
                ->latest('id')->paginate(20)->withQueryString(),
            'suppliers'=>TradeParty::where('type','supplier')->where('is_active',true)->orderBy('name')->get(),
            'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
        ]);
    }

    public function saveRule(Request $request)
    {
        abort_unless($request->user()->canAny(['inventory.update','administration.update']),403);
        $data=$request->validate([
            'inventory_material_id'=>['required',Rule::exists('inventory_materials','id')->where('is_active',true)],
            'minimum_quantity'=>['required','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/'],
            'target_quantity'=>['required','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/'],
            'lead_days'=>['required','integer','between:0,3650'],
            'supplier_id'=>['nullable',Rule::exists('trade_parties','id')->where('type','supplier')->where('is_active',true)],
        ]);
        if(BigDecimal::of($data['target_quantity'])->isLessThanOrEqualTo($data['minimum_quantity']))
            throw ValidationException::withMessages(['target_quantity'=>'Target quantity must exceed minimum quantity.']);
        DB::transaction(function()use($data,$request){
            DB::table('material_reorder_settings')->updateOrInsert(
                ['inventory_material_id'=>$data['inventory_material_id']],
                ['minimum_quantity'=>$data['minimum_quantity'],'target_quantity'=>$data['target_quantity'],
                    'lead_days'=>$data['lead_days'],'supplier_id'=>$data['supplier_id']??null,
                    'updated_by'=>$request->user()->id,'updated_at'=>now()],
            );
            ActivityLog::record('inventory.reorder.rule','inventory_material',$data['inventory_material_id'],
                'Material replenishment rule created or updated.');
        });
        return back()->with('success','Reorder threshold saved.');
    }

    public function submit(Request $request,ProcurementRequestService $service)
    {
        $this->guard($request,true);
        $data=$request->validate([
            'submission_token'=>['required','uuid'],
            'requested_on'=>['required','date','before_or_equal:today'],
            'required_on'=>['nullable','date','after_or_equal:requested_on'],
            'notes'=>['nullable','string','max:3000'],
            'lines'=>['required','array','min:1','max:100'],
            'lines.*.inventory_material_id'=>['required','integer',Rule::exists('inventory_materials','id')->where('is_active',true)],
            'lines.*.quantity'=>['required','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/','numeric','gt:0'],
            'lines.*.description'=>['nullable','string','max:255'],
        ]);
        $service->submit($data,$request->user()->id);
        return back()->with('success','Material requisition submitted for independent review.');
    }

    public function decide(Request $request,ProcurementRequest $requisition,ProcurementRequestService $service)
    {
        abort_unless($request->user()->canAny(['purchases.update','administration.update']),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approved','rejected'])],
            'decision_note'=>['nullable','required_if:decision,rejected','string','max:1000'],
        ]);
        $service->decide($requisition,$data['decision'],$data['decision_note']??null,$request->user()->id);
        return back()->with('success','Requisition decision recorded.');
    }

    public function convert(Request $request,ProcurementRequest $requisition,ProcurementRequestService $service)
    {
        abort_unless($request->user()->canAny(['purchases.create','administration.update']),403);
        $data=$request->validate([
            'supplier_id'=>['required',Rule::exists('trade_parties','id')->where('type','supplier')->where('is_active',true)],
            'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
            'source'=>['required',Rule::in(['local','foreign'])],
            'order_date'=>['required','date','before_or_equal:today'],
            'expected_date'=>['nullable','date','after_or_equal:order_date'],
            'unit_prices'=>['required','array','min:1','max:100'],
            'unit_prices.*'=>['required','numeric','min:0','decimal:0,4','max:999999999999.9999'],
            'notes'=>['nullable','string','max:2500'],
        ]);
        $order=$service->convert($requisition,$data,$request->user()->id);
        return redirect()->route('purchases.orders.show',$order)
            ->with('success','Draft PO created from approved requisition. Review prices and confirm the PO before goods receipt.');
    }
}
