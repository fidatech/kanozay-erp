<?php

namespace App\Http\Controllers;

use App\Models\CompanyWarehouse;
use App\Support\LocalizedDecimal;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturnRecord;
use App\Services\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseReturnController extends Controller
{
    private function check(Request $request,bool $write=false):void
    {
        $permissions=$write?['purchases.update','inventory.update','administration.update']:
            ['purchases.view','inventory.view','administration.view'];
        abort_unless($request->user()->canAny($permissions),403);
    }

    public function index(Request $request,PurchaseReturnService $service)
    {
        $this->check($request);
        $input=$request->validate(['receipt_id'=>['nullable','integer','exists:goods_receipts,id']]);
        $receipt=null;
        $remaining=[];
        if(!empty($input['receipt_id'])){
            $receipt=GoodsReceipt::with(['lines.material','purchaseOrder.supplier','purchaseOrder.currency'])
                ->where('status','posted')->findOrFail($input['receipt_id']);
            $remaining=$service->remaining($receipt);
        }
        $receipts=GoodsReceipt::with('purchaseOrder.supplier')
            ->where('status','posted')->orderByDesc('id')->limit(400)->get();
        $returns=PurchaseReturnRecord::with(['receipt','order.supplier','currency'])
            ->orderByDesc('id')->paginate(25)->withQueryString();
        return view('purchases.returns',compact('receipt','remaining','receipts','returns')+[
            'warehouses'=>CompanyWarehouse::where('is_active',true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request,PurchaseReturnService $service)
    {
        // Stock posting and procurement approval both required.
        abort_unless(
            $request->user()->can('administration.update') ||
            ($request->user()->can('purchases.update') && $request->user()->can('inventory.update')),403);
        $request->merge(['quantities'=>LocalizedDecimal::quantities($request->input('quantities'))]);
        $data=$request->validate([
            'goods_receipt_id'=>['required','integer',Rule::exists('goods_receipts','id')->where('status','posted')],
            'submission_token'=>['required','uuid'],
            'returned_on'=>['required','date','before_or_equal:today'],
            'supplier_reference'=>['nullable','string','max:120'],
            'company_warehouse_id'=>['nullable',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'reason'=>['required','string','min:10','max:2500'],
            'quantities'=>['required','array','min:1','max:100'],
            'quantities.*'=>['nullable','regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/'],
        ]);
        $receipt=GoodsReceipt::findOrFail($data['goods_receipt_id']);
        $return=$service->post($receipt,$data,$request->user()->id);
        return redirect()->route('purchases.returns.show',$return)
            ->with('success','Return posted to inventory. Supplier credit requires reconciliation.');
    }

    public function show(Request $request,PurchaseReturnRecord $return)
    {
        $this->check($request);
        $return->load(['receipt','order.supplier','currency','lines.material','lines.receiptLine']);
        return view('purchases.return-show',compact('return'));
    }
}
